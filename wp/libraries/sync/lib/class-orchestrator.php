<?php
/**
 * Dev-side step engine. The browser (or WP-CLI) calls analyze, plan,
 * confirm and then step repeatedly; every call is one short request.
 */
namespace aw2\sync;

class Orchestrator {

	const MAX_ATTEMPTS = 3;

	/** @var Sync_Client */
	private $client;
	/** @var Site_Resolver */
	private $site;
	/** @var Transformer */
	private $transformer;

	public function __construct(?Sync_Client $client = null, ?Site_Resolver $site = null) {
		$this->client      = $client ?: Sync_Client::from_config();
		$this->site        = $site ?: new WP_Site_Resolver(true);
		$this->transformer = new Transformer($this->site, Reference_Registry::instance());
	}

	/* ================================================================== */
	/* Phase 0-1: guard, extract, handshake                                */
	/* ================================================================== */

	public function analyze(int $post_id, int $user_id): array {
		if (!is_source()) {
			throw new Sync_Exception('not_source', 'This site is not configured as a sync source (AWX_SYNC_ROLE).');
		}
		$extractor = new Extractor($this->site, $this->transformer);
		$manifest  = $extractor->analyze($post_id);
		$manifest['registry_hash'] = Reference_Registry::instance()->hash();
		$manifest['source_hosts']  = $this->site->own_hosts();
		$manifest['source_home']   = $this->site->home_url();

		$hs = $this->client->post('/handshake', array('protocol' => AWX_SYNC_PROTOCOL, 'probe_url' => Export_Routes::probe_url()));
		$this->throw_if_error($hs, 'handshake');
		$manifest['handshake'] = $hs;

		if ((int) ($hs['protocol'] ?? 0) !== (int) AWX_SYNC_PROTOCOL) {
			throw new Sync_Exception('protocol_mismatch', 'The target runs sync protocol ' . ($hs['protocol'] ?? '?') . ', this site runs ' . AWX_SYNC_PROTOCOL . '. Update the plugin on the older site.');
		}
		if (($hs['registry_hash'] ?? '') !== $manifest['registry_hash']) {
			throw new Sync_Exception('registry_mismatch', 'The reference registry differs between the two sites; update the plugin on both so they agree on where references sit.');
		}
		if (!\in_array($manifest['page']['type'], (array) ($hs['post_types'] ?? array()), true)) {
			throw new Sync_Exception('post_type_not_allowed', 'The target does not accept the post type ' . $manifest['page']['type'] . '.');
		}

		Manifest_Store::supersede($post_id);
		$run_id = Manifest_Store::create($post_id, $manifest['page']['uuid'], $manifest, $this->client->target_url(), $user_id);
		return array(
			'run_id'    => $run_id,
			'page'      => array('uuid' => $manifest['page']['uuid'], 'title' => $manifest['page']['title'], 'type' => $manifest['page']['type'], 'status' => $manifest['page']['status']),
			'counts'    => array('media' => \count($manifest['media']), 'blocks' => \count($manifest['blocks']), 'posts' => \count($manifest['posts']), 'terms' => \count($manifest['terms'])),
			'warnings'  => $manifest['warnings'],
			'handshake' => $hs,
		);
	}

	/* ================================================================== */
	/* Phase 2: plan                                                       */
	/* ================================================================== */

	public function plan(string $run_id): array {
		$run = $this->load($run_id, array('planning', 'awaiting_confirm', 'failed', 'running'));
		$m   = $run['manifest'];
		$req = array(
			'page'       => array('uuid' => $m['page']['uuid'], 'type' => $m['page']['type'], 'status' => $m['page']['status'], 'hash' => $m['page']['hash']),
			'media'      => \array_map(function ($x) { return array('sha256' => $x['sha256'], 'bytes' => $x['bytes']); }, $m['media']),
			'blocks'     => \array_map(function ($b) { return array('uuid' => $b['uuid'], 'hash' => $b['hash']); }, $m['blocks']),
			'posts'      => $m['posts'],
			'terms'      => $m['terms'],
			'dgb_blocks' => $m['dgb_blocks'],
			'modules'    => $m['modules'],
			'post_types' => $m['post_types'],
		);
		$answer = $this->client->post('/plan', $req);
		$this->throw_if_error($answer, 'plan');

		$built = Planner::build($m, $answer, (array) ($m['handshake'] ?? array()), 'wp_generate_uuid4');
		$built['target'] = $answer;
		$built['overwrite'] = false;

		Step_Store::delete_run($run_id);
		Step_Store::create($run_id, $built['steps']);
		$status = !empty($built['blockers']) ? 'blocked' : ($built['summary']['nothing_to_do'] ? 'done' : 'awaiting_confirm');
		Manifest_Store::update($run_id, array('plan' => $built, 'status' => $status, 'media_mode' => $built['media_mode'], 'error' => null));
		return $this->view($run_id);
	}

	/** User confirmed the dry run. */
	public function confirm(string $run_id, bool $overwrite = false): array {
		$run  = $this->load($run_id, array('awaiting_confirm'));
		$plan = $run['plan'];
		if (!empty($plan['summary']['edited_on_prod']) && !$overwrite) {
			throw new Sync_Exception('overwrite_required', 'The target page was edited since the last sync; confirm the overwrite explicitly.');
		}
		$plan['overwrite'] = $overwrite;
		Manifest_Store::update($run_id, array('plan' => $plan, 'status' => 'running'));
		return $this->view($run_id);
	}

	/* ================================================================== */
	/* Phases 3-6: one step per call                                       */
	/* ================================================================== */

	public function step(string $run_id, ?string $step_id = null): array {
		$run  = $this->load($run_id, array('running'));
		$step = $step_id !== null ? Step_Store::get($run_id, $step_id) : $this->next_step($run_id);
		if ($step === null) {
			if ($step_id !== null) {
				throw new Sync_Exception('step_not_found', 'Unknown step.');
			}
			Manifest_Store::update($run_id, array('status' => 'done'));
			return $this->view($run_id);
		}
		if ($step['status'] === 'done') {
			return $this->view($run_id) + array('step' => $this->step_view($step));
		}
		$payload = \is_array($step['response']) ? $step['response'] : array();
		if (isset($payload['result'])) {
			$payload = (array) ($payload['input'] ?? array());
		}

		try {
			switch ($step['type']) {
				case 'media':
					$result = $this->exec_media($run, $step, $payload);
					break;
				case 'block':
					$result = $this->exec_block($run, $step);
					break;
				case 'page':
					$result = $this->exec_page($run, $step);
					break;
				default:
					throw new Sync_Exception('bad_step', 'Unknown step type ' . $step['type']);
			}
		} catch (Sync_Exception $e) {
			$attempts  = (int) $step['attempts'] + 1;
			$retryable = !empty($e->details['retryable']) && $attempts < self::MAX_ATTEMPTS;
			Step_Store::record($run_id, $step['step_id'], $step['type'], $step['object_uuid'], $retryable ? 'pending' : 'failed', array('input' => $payload, 'error' => $e->code_slug), $e->getMessage());
			if (!$retryable) {
				Manifest_Store::update($run_id, array('status' => 'failed', 'error' => $e->code_slug . ': ' . $e->getMessage()));
			}
			$view = $this->view($run_id);
			$view['step'] = $this->step_view(Step_Store::get($run_id, $step['step_id']));
			$view['error'] = array('code' => $e->code_slug, 'message' => $e->getMessage(), 'retryable' => $retryable, 'details' => $e->details);
			return $view;
		}

		Step_Store::record($run_id, $step['step_id'], $step['type'], $step['object_uuid'], 'done', array('input' => $payload, 'result' => $result));
		if ($step['type'] === 'page') {
			\update_post_meta((int) $run['post_id'], Identity::META_CONTENT_HASH, (string) $run['manifest']['page']['hash']);
			\update_post_meta((int) $run['post_id'], '_awx_last_sync', array('run_id' => $run_id, 'time' => \time(), 'permalink' => $result['permalink'] ?? null, 'revision_id' => $result['revision_id'] ?? null, 'target_id' => $result['id'] ?? null));
			Manifest_Store::update($run_id, array('status' => 'done', 'error' => null));
		}
		$view = $this->view($run_id);
		$view['step'] = $this->step_view(Step_Store::get($run_id, $step['step_id']));
		return $view;
	}

	/** Re-plan an interrupted run; the user confirms again. */
	public function resume(string $run_id): array {
		$this->load($run_id, array('running', 'failed', 'awaiting_confirm', 'planning', 'blocked'));
		Manifest_Store::update($run_id, array('status' => 'planning', 'error' => null));
		return $this->plan($run_id);
	}

	public function cancel(string $run_id): array {
		$this->load($run_id);
		Manifest_Store::update($run_id, array('status' => 'cancelled'));
		return $this->view($run_id);
	}

	public function status(string $run_id): array {
		return $this->view($run_id);
	}

	/* ================================================================== */
	/* Step executors                                                      */
	/* ================================================================== */

	private function exec_media(array $run, array $step, array $payload): array {
		$uuid = (string) $step['object_uuid'];
		$info = (array) ($run['manifest']['media'][$uuid] ?? array());
		if (empty($info)) {
			throw new Sync_Exception('bad_step', 'Media not in manifest.');
		}
		$meta = array('uuid' => $uuid, 'sha256' => $info['sha256'], 'mime' => $info['mime'], 'file' => $info['file'], 'alt' => $info['alt'], 'title' => $info['title']);
		if (($payload['mode'] ?? 'push') === 'pull') {
			$res = $this->client->post('/media/pull', array('run_id' => $run['run_id'], 'step_id' => $step['step_id'], 'fetch_url' => Export_Routes::signed_media_url($uuid, $run['run_id']), 'media' => $meta));
		} else {
			$path = Export_Routes::original_path((int) $info['id']);
			if ($path === null) {
				throw new Sync_Exception('file_missing', 'The original file of ' . $info['file'] . ' is missing on this site.');
			}
			$res = $this->client->post_file('/media', array('run_id' => $run['run_id'], 'step_id' => $step['step_id'], 'media' => $meta), $path, (string) $info['file'], (string) $info['mime']);
		}
		$this->throw_if_error($res, 'media ' . $info['file']);
		return $res;
	}

	private function exec_block(array $run, array $step): array {
		$uuid  = (string) $step['object_uuid'];
		$block = null;
		foreach ((array) $run['manifest']['blocks'] as $b) {
			if ($b['uuid'] === $uuid) {
				$block = $b;
				break;
			}
		}
		if ($block === null) {
			throw new Sync_Exception('bad_step', 'Block not in manifest.');
		}
		$res = $this->client->post('/blocks', array(
			'run_id'       => $run['run_id'],
			'step_id'      => $step['step_id'],
			'uuid'         => $uuid,
			'slug'         => $block['slug'],
			'title'        => $block['title'],
			'content'      => $block['content'],
			'hash'         => $block['hash'],
			'pattern_slug' => $block['pattern_slug'],
			'terms'        => $run['manifest']['terms'],
			'source_hosts' => $run['manifest']['source_hosts'],
		));
		$this->throw_if_error($res, 'block ' . $block['title']);
		return $res;
	}

	private function exec_page(array $run, array $step): array {
		$m  = $run['manifest'];
		$pp = (array) ($run['plan']['target']['page'] ?? array());
		$res = $this->client->post('/pages', \array_merge($m['page'], array(
			'run_id'             => $run['run_id'],
			'step_id'            => $step['step_id'],
			'expected_prod_hash' => $pp['prod_hash'] ?? null,
			'overwrite'          => !empty($run['plan']['overwrite']),
			'terms_catalog'      => $m['terms'],
			'source_hosts'       => $m['source_hosts'],
		)));
		$this->throw_if_error($res, 'page');
		return $res;
	}

	/* ================================================================== */
	/* Helpers                                                             */
	/* ================================================================== */

	private function load(string $run_id, array $statuses = array()): array {
		$run = Manifest_Store::get($run_id);
		if ($run === null) {
			throw new Sync_Exception('run_not_found', 'Unknown run.');
		}
		if (!empty($statuses) && !\in_array($run['status'], $statuses, true)) {
			throw new Sync_Exception('bad_state', 'The run is ' . $run['status'] . '; this action needs ' . \implode(' or ', $statuses) . '.');
		}
		return $run;
	}

	private function next_step(string $run_id): ?array {
		foreach (Step_Store::for_run($run_id) as $s) {
			if ($s['status'] === 'pending') {
				return $s;
			}
		}
		return null;
	}

	private function throw_if_error($res, string $what): void {
		if (\is_wp_error($res)) {
			$data = (array) $res->get_error_data();
			throw new Sync_Exception(\preg_replace('/^awx_sync_/', '', $res->get_error_code()), "{$what}: " . $res->get_error_message(), array(
				'status'    => $data['status'] ?? 0,
				'retryable' => !empty($data['retryable']),
				'details'   => $data['details'] ?? array(),
			));
		}
	}

	private function step_view(?array $s): ?array {
		if ($s === null) {
			return null;
		}
		$r = \is_array($s['response']) ? $s['response'] : array();
		return array('step_id' => $s['step_id'], 'seq' => (int) $s['seq'], 'type' => $s['type'], 'object_uuid' => $s['object_uuid'], 'status' => $s['status'], 'attempts' => (int) $s['attempts'], 'input' => $r['input'] ?? ($r ?: null), 'result' => $r['result'] ?? null, 'error' => $s['error']);
	}

	public function view(string $run_id): array {
		$run   = Manifest_Store::get($run_id);
		$steps = \array_map(array($this, 'step_view'), Step_Store::for_run($run_id));
		$done  = \count(\array_filter($steps, function ($s) { return $s['status'] === 'done'; }));
		$next  = null;
		foreach ($steps as $s) {
			if ($s['status'] === 'pending') {
				$next = $s['step_id'];
				break;
			}
		}
		$last = \get_post_meta((int) $run['post_id'], '_awx_last_sync', true);
		return array(
			'run_id'     => $run_id,
			'status'     => $run['status'],
			'post_id'    => (int) $run['post_id'],
			'target_url' => $run['target_url'],
			'media_mode' => $run['media_mode'],
			'error'      => $run['error'],
			'plan'       => $run['plan'] ? array('summary' => $run['plan']['summary'], 'warnings' => $run['plan']['warnings'], 'blockers' => $run['plan']['blockers'], 'overwrite' => $run['plan']['overwrite'] ?? false) : null,
			'steps'      => $steps,
			'progress'   => array('done' => $done, 'total' => \count($steps)),
			'next_step'  => $next,
			'last_sync'  => \is_array($last) ? $last : null,
		);
	}
}
