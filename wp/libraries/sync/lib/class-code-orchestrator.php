<?php
/**
 * Dev-side step engine for code deploys. Same phases as the page
 * Orchestrator (analyze → plan → confirm → step… → done) and the same step
 * executors for the content it carries (app pages/posts, patterns); adds
 * the code, acf and finalize steps.
 */
namespace aw2\sync;

class Code_Orchestrator extends Orchestrator {

	/** @var Code_Catalog */
	private $catalog;

	public function __construct(?Sync_Client $client = null, ?Site_Resolver $site = null, ?Code_Catalog $catalog = null) {
		parent::__construct($client, $site);
		$this->catalog = $catalog ?: new Code_Catalog();
	}

	/* ================================================================== */
	/* Phase 1: expand the selection, handshake, extract content           */
	/* ================================================================== */

	public function analyze_selection(array $selection, int $user_id, string $label = ''): array {
		if (!is_source()) {
			throw new Sync_Exception('not_source', 'This site is not configured as a sync source (AWX_SYNC_ROLE).');
		}
		$exp = $this->catalog->items_for($selection);
		if (empty($exp['items']) && empty($exp['content'])) {
			throw new Sync_Exception('empty_selection', 'Nothing matched the selection.');
		}

		$hs = $this->client->post('/handshake', array('protocol' => AWX_SYNC_PROTOCOL, 'probe_url' => Export_Routes::probe_url()));
		$this->throw_if_error($hs, 'handshake');
		if ((int) ($hs['protocol'] ?? 0) !== (int) AWX_SYNC_PROTOCOL) {
			throw new Sync_Exception('protocol_mismatch', 'The target runs sync protocol ' . ($hs['protocol'] ?? '?') . ', this site runs ' . AWX_SYNC_PROTOCOL . '. Update the plugin on the older site.');
		}
		$registry_hash = Reference_Registry::instance()->hash();
		if (!empty($exp['content']) && ($hs['registry_hash'] ?? '') !== $registry_hash) {
			throw new Sync_Exception('registry_mismatch', 'The reference registry differs between the two sites; update the plugin on both before deploying pages or patterns.');
		}

		$items = $ids = array();
		foreach ($exp['items'] as $it) {
			/** @var Code_Item $it */
			$items[] = $it->to_payload();
			$ids[$it->key()] = $it->id;
		}

		$extractor = new Extractor($this->site, $this->transformer);
		$content = array();
		foreach ($exp['content'] as $c) {
			try {
				if ($c['kind'] === 'page' || $c['kind'] === 'template') {
					$c['manifest'] = $extractor->analyze((int) $c['post_id']);
					$c['title'] = (string) ($c['manifest']['page']['title'] ?? ('#' . $c['post_id']));
					$c['slug']  = (string) ($c['manifest']['page']['slug'] ?? '');
				} else {
					$c['manifest'] = $extractor->analyze_block((int) $c['block_id']);
				}
			} catch (Sync_Exception $e) {
				$c['manifest'] = null;
				$c['error'] = array('code' => $e->code_slug, 'message' => $e->getMessage());
				if (empty($c['title'])) {
					$c['title'] = isset($c['post_id']) ? ('#' . $c['post_id']) : (string) ($c['slug'] ?? '');
				}
			}
			$content[] = $c;
		}

		$manifest = array(
			'protocol'      => AWX_SYNC_PROTOCOL,
			'selection'     => $selection,
			'items'         => $items,
			'item_ids'      => $ids,
			'content'       => $content,
			'collections'   => $exp['collections'],
			'apps'          => $exp['apps'],
			'notes'         => $exp['notes'],
			'handshake'     => $hs,
			'registry_hash' => $registry_hash,
			'source_hosts'  => $this->site->own_hosts(),
			'source_home'   => $this->site->home_url(),
		);
		$run_id = Manifest_Store::create(0, '', $manifest, $this->client->target_url(), $user_id, 'push', 'code', $label);
		return array(
			'run_id'   => $run_id,
			'counts'   => array('items' => \count($items), 'content' => \count($content)),
			'warnings' => $exp['notes'],
			'handshake'=> array('allow_code' => $hs['allow_code'] ?? null, 'acf_active' => $hs['acf_active'] ?? null, 'home_url' => $hs['home_url'] ?? null),
		);
	}

	/* ================================================================== */
	/* Phase 2: plan                                                       */
	/* ================================================================== */

	public function plan(string $run_id): array {
		$run = $this->load_code($run_id, array('planning', 'awaiting_confirm', 'failed', 'running', 'blocked'));
		$m = $run['manifest'];

		$req_items = $acf_keys = array();
		foreach ((array) $m['items'] as $it) {
			if ($it['kind'] === 'acf') {
				$acf_keys[] = array('key' => $it['slug'], 'hash' => $it['hash']);
			} else {
				$req_items[] = array('kind' => $it['kind'], 'post_type' => $it['post_type'], 'slug' => $it['slug'], 'hash' => $it['hash']);
			}
		}
		$answer = $this->client->post('/code/plan', array(
			'run_id'      => $run_id,
			'items'       => $req_items,
			'acf_keys'    => $acf_keys,
			'collections' => \array_map(function ($c) { return array('kind' => $c['kind'], 'post_type' => $c['post_type']); }, (array) $m['collections']),
			'apps'        => (array) $m['apps'],
		));
		$code_blockers = array();
		if (\is_wp_error($answer)) {
			$data = (array) $answer->get_error_data();
			$status = (int) ($data['status'] ?? 0);
			if (\in_array($status, array(403, 404), true)) {
				// Route missing or token lacks the code scope: the plan shows the reason instead of failing.
				$why = $answer->get_error_message();
				$answer = array('items' => array(), 'acf' => array(), 'only_on_prod' => array(), 'apps' => array(), 'allow_code' => false);
				$code_blockers[] = array('code' => $status === 404 ? 'code_routes_missing' : 'code_scope_denied', 'message' => $status === 404
					? "The target has no code deploy routes. Update the plugin there and add define('AWX_SYNC_ALLOW_CODE', true); to its wp-config.php."
					: 'The token lacks the code scope (' . $why . '). Create a token with the Code deploy scope on the target.');
			} else {
				$this->throw_if_error($answer, 'code plan');
			}
		}

		$subplans = array();
		foreach ((array) $m['content'] as $i => $c) {
			if (empty($c['manifest'])) {
				continue;
			}
			$sm = $c['manifest'];
			$sm['registry_hash'] = $m['registry_hash'];
			$sm['source_hosts']  = $m['source_hosts'];
			$req = array(
				'page'       => !empty($sm['page']) ? array('uuid' => $sm['page']['uuid'], 'type' => $sm['page']['type'], 'status' => $sm['page']['status'], 'hash' => $sm['page']['hash']) : null,
				'media'      => \array_map(function ($x) { return array('sha256' => $x['sha256'], 'bytes' => $x['bytes']); }, (array) $sm['media']),
				'blocks'     => \array_map(function ($b) { return array('uuid' => $b['uuid'], 'hash' => $b['hash']); }, (array) $sm['blocks']),
				'posts'      => $sm['posts'],
				'terms'      => $sm['terms'],
				'dgb_blocks' => $sm['dgb_blocks'],
				'modules'    => $sm['modules'],
				'post_types' => $sm['post_types'],
			);
			$sub_answer = $this->client->post('/plan', $req);
			$this->throw_if_error($sub_answer, 'plan for ' . ($c['title'] ?? $c['kind']));
			$subplans[$i] = array('answer' => $sub_answer, 'built' => Planner::build($sm, $sub_answer, (array) $m['handshake'], 'wp_generate_uuid4'));
		}

		$built = Code_Planner::rows($m, \is_array($answer) ? $answer : array(), $subplans);
		$built['blockers'] = \array_merge($code_blockers, $built['blockers']);
		$built['summary']['blocked_run'] = !empty($built['blockers']);
		$built['target']   = \is_array($answer) ? $answer : array();
		$built['subplans'] = $subplans;
		$built['overwrite'] = false;
		$built['selected_keys'] = $run['plan']['selected_keys'] ?? $built['selected_default'];

		Step_Store::delete_run($run_id);
		$status = !empty($built['blockers']) ? 'blocked' : (!empty($built['summary']['nothing_to_do']) ? 'done' : 'awaiting_confirm');
		Manifest_Store::update($run_id, array('plan' => $built, 'status' => $status, 'error' => null));
		return $this->view($run_id);
	}

	/** The developer confirmed the ticked rows. */
	public function confirm_selection(string $run_id, array $selected_keys, bool $overwrite = false): array {
		$run  = $this->load_code($run_id, array('awaiting_confirm'));
		$plan = $run['plan'];
		try {
			$built = Code_Planner::steps($plan, $selected_keys, 'wp_generate_uuid4');
		} catch (\InvalidArgumentException $e) {
			throw new Sync_Exception('invalid_selection', $e->getMessage());
		}
		if (empty($built['steps'])) {
			throw new Sync_Exception('empty_selection', 'No item is ticked that needs a write.');
		}
		if ($built['overwrite_needed'] && !$overwrite) {
			throw new Sync_Exception('overwrite_required', 'Some ticked items were edited on the target since the last deploy; confirm the overwrite explicitly.');
		}
		$plan['overwrite']     = $overwrite;
		$plan['selected_keys'] = $built['selected'];
		foreach ($plan['rows'] as &$r) {
			$r['selected'] = \in_array($r['key'], $built['selected'], true);
		}
		unset($r);
		$plan['summary'] = Code_Planner::summarize($plan['rows']) + array('blocked_run' => false);
		Step_Store::delete_run($run_id);
		Step_Store::create($run_id, $built['steps']);
		Manifest_Store::update($run_id, array('plan' => $plan, 'status' => 'running', 'error' => null));
		return $this->view($run_id);
	}

	/* ================================================================== */
	/* Phase 3: steps                                                      */
	/* ================================================================== */

	public function step(string $run_id, ?string $step_id = null): array {
		$run  = $this->load_code($run_id, array('running'));
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
				case 'code':
					$result = $this->exec_code($run, $step, $payload);
					break;
				case 'acf':
					$result = $this->exec_acf($run, $step, $payload);
					break;
				case 'option':
					$result = $this->exec_option($run, $step, $payload);
					break;
				case 'menu':
					$result = $this->exec_menu($run, $step, $payload);
					break;
				case 'finalize':
					$result = $this->client->post('/code/finalize', array('run_id' => $run_id, 'step_id' => $step['step_id']));
					$this->throw_if_error($result, 'finalize');
					break;
				case 'media':
				case 'block':
				case 'page':
					$result = $this->exec_content($run, $step, $payload);
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
			$view['step']  = $this->step_view(Step_Store::get($run_id, $step['step_id']));
			$view['error'] = array('code' => $e->code_slug, 'message' => $e->getMessage(), 'retryable' => $retryable, 'details' => $e->details);
			return $view;
		}

		Step_Store::record($run_id, $step['step_id'], $step['type'], $step['object_uuid'], 'done', array('input' => $payload, 'result' => $result));
		if ($step['type'] === 'finalize') {
			Manifest_Store::update($run_id, array('status' => 'done', 'error' => null));
			if (!empty($run['label'])) {
				Deploy_Sets::touch((string) $run['label'], $run_id);
			}
		}
		$view = $this->view($run_id);
		$view['step'] = $this->step_view(Step_Store::get($run_id, $step['step_id']));
		return $view;
	}

	public function resume(string $run_id): array {
		$this->load_code($run_id, array('running', 'failed', 'awaiting_confirm', 'planning', 'blocked'));
		Manifest_Store::update($run_id, array('status' => 'planning', 'error' => null));
		return $this->plan($run_id);
	}

	/* ---- executors ---- */

	private function exec_code(array $run, array $step, array $payload): array {
		$item = $this->find_item($run, (string) $step['object_uuid']);
		$res = $this->client->post('/code/modules', array(
			'run_id'             => $run['run_id'],
			'step_id'            => $step['step_id'],
			'item'               => $item,
			'expected_prod_hash' => $payload['expected_prod_hash'] ?? null,
			'overwrite'          => !empty($run['plan']['overwrite']),
		));
		$this->throw_if_error($res, $item['post_type'] . ':' . $item['slug']);
		Code_Catalog::mark_synced($run['manifest']['item_ids'][$step['object_uuid']] ?? null, $run['run_id'], (string) $item['hash']);
		return $res;
	}

	private function exec_acf(array $run, array $step, array $payload): array {
		$item = $this->find_item($run, (string) $step['object_uuid']);
		$res = $this->client->post('/code/acf-field-groups', array(
			'run_id'             => $run['run_id'],
			'step_id'            => $step['step_id'],
			'key'                => $item['slug'],
			'group'              => $item['group'] ?? array(),
			'hash'               => $item['hash'],
			'expected_prod_hash' => $payload['expected_prod_hash'] ?? null,
			'overwrite'          => !empty($run['plan']['overwrite']),
		));
		$this->throw_if_error($res, 'ACF group ' . $item['slug']);
		Code_Catalog::mark_synced($run['manifest']['item_ids'][$step['object_uuid']] ?? null, $run['run_id'], (string) $item['hash']);
		return $res;
	}

	private function exec_option(array $run, array $step, array $payload): array {
		$item = $this->find_item($run, (string) $step['object_uuid']);
		$res = $this->client->post('/code/options', array(
			'run_id'             => $run['run_id'],
			'step_id'            => $step['step_id'],
			'option'             => $item['slug'],
			'item'               => $item,
			'value'              => \json_decode((string) ($item['content'] ?? ''), true),
			'hash'               => $item['hash'],
			'expected_prod_hash' => $payload['expected_prod_hash'] ?? null,
			'overwrite'          => !empty($run['plan']['overwrite']),
		));
		$this->throw_if_error($res, 'Option ' . $item['slug']);
		Code_Catalog::mark_option_synced($item['slug'], $run['run_id'], (string) $item['hash']);
		return $res;
	}

	private function exec_menu(array $run, array $step, array $payload): array {
		$item = $this->find_item($run, (string) $step['object_uuid']);
		$res = $this->client->post('/code/menus', array(
			'run_id'             => $run['run_id'],
			'step_id'            => $step['step_id'],
			'slug'               => $item['slug'],
			'item'               => $item,
			'content'            => $item['content'] ?? '',
			'hash'               => $item['hash'],
			'expected_prod_hash' => $payload['expected_prod_hash'] ?? null,
			'overwrite'          => !empty($run['plan']['overwrite']),
		));
		$this->throw_if_error($res, 'Menu ' . $item['slug']);
		Code_Catalog::mark_menu_synced($item['slug'], $run['run_id'], (string) $item['hash']);
		return $res;
	}

	/** Media, block and page steps run against the content item's own manifest and plan answer. */
	private function exec_content(array $run, array $step, array $payload): array {
		$i = isset($payload['sub']) ? (int) $payload['sub'] : -1;
		$c = $run['manifest']['content'][$i] ?? null;
		if (!$c || empty($c['manifest'])) {
			throw new Sync_Exception('bad_step', 'Content item not in manifest.');
		}
		$sm = $c['manifest'];
		$sm['source_hosts'] = $run['manifest']['source_hosts'];
		$pseudo = array(
			'run_id'   => $run['run_id'],
			'post_id'  => $c['post_id'] ?? 0,
			'manifest' => $sm,
			'plan'     => array('target' => $run['plan']['subplans'][$i]['answer'] ?? array(), 'overwrite' => !empty($run['plan']['overwrite'])),
		);
		switch ($step['type']) {
			case 'media':
				$result = $this->exec_media($pseudo, $step, $payload);
				break;
			case 'block':
				$result = $this->exec_block($pseudo, $step);
				if ($c['kind'] === 'pattern') {
					Code_Catalog::mark_synced((int) $c['block_id'], $run['run_id'], (string) ($sm['blocks'][\count($sm['blocks']) - 1]['hash'] ?? ''));
				}
				break;
			default:
				$result = $this->exec_page($pseudo, $step);
				\update_post_meta((int) $c['post_id'], Identity::META_CONTENT_HASH, (string) $sm['page']['hash']);
				\update_post_meta((int) $c['post_id'], '_awx_last_sync', array('run_id' => $run['run_id'], 'time' => \time(), 'permalink' => $result['permalink'] ?? null, 'revision_id' => $result['revision_id'] ?? null, 'target_id' => $result['id'] ?? null));
				Code_Catalog::mark_synced((int) $c['post_id'], $run['run_id'], (string) $sm['page']['hash']);
		}
		return $result;
	}

	private function find_item(array $run, string $key): array {
		foreach ((array) $run['manifest']['items'] as $it) {
			if ($it['post_type'] . ':' . $it['slug'] === $key) {
				return $it;
			}
		}
		throw new Sync_Exception('bad_step', 'Item ' . $key . ' not in manifest.');
	}

	/* ---- views ---- */

	protected function load_code(string $run_id, array $statuses = array()): array {
		$run = $this->load($run_id, $statuses);
		if (($run['kind'] ?? 'page') !== 'code') {
			throw new Sync_Exception('bad_state', 'This run is a page sync, not a code deploy.');
		}
		return $run;
	}

	public function view(string $run_id): array {
		$run   = Manifest_Store::get($run_id);
		if ($run === null) {
			throw new Sync_Exception('run_not_found', 'Unknown run.');
		}
		if (($run['kind'] ?? 'page') !== 'code') {
			return parent::view($run_id);
		}
		$steps = \array_map(array($this, 'step_view'), Step_Store::for_run($run_id));
		$done  = \count(\array_filter($steps, function ($s) { return $s['status'] === 'done'; }));
		$next  = null;
		foreach ($steps as $s) {
			if ($s['status'] === 'pending') {
				$next = $s['step_id'];
				break;
			}
		}
		$plan = $run['plan'];
		return array(
			'run_id'     => $run_id,
			'kind'       => 'code',
			'label'      => $run['label'] ?? '',
			'status'     => $run['status'],
			'target_url' => $run['target_url'],
			'error'      => $run['error'],
			'created_at' => $run['created_at'],
			'selection'  => $run['manifest']['selection'] ?? null,
			'plan'       => $plan ? array(
				'rows'          => $plan['rows'],
				'blockers'      => $plan['blockers'],
				'warnings'      => $plan['warnings'],
				'summary'       => $plan['summary'],
				'selected_keys' => $plan['selected_keys'] ?? array(),
				'overwrite'     => $plan['overwrite'] ?? false,
				'target_home'   => $run['manifest']['handshake']['home_url'] ?? null,
			) : null,
			'steps'      => $steps,
			'progress'   => array('done' => $done, 'total' => \count($steps)),
			'next_step'  => $next,
		);
	}
}
