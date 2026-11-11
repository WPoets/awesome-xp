<?php
/**
 * Abilities for the Dev side: the editor UI, `wp awx sync` and MCP agents
 * all drive the same Orchestrator through awx_execute_ability().
 */
namespace aw2\sync\abilities;

use aw2\abilities\operations\Operation_Base;
use aw2\sync\Orchestrator;
use aw2\sync\Sync_Exception;
use aw2\sync\Manifest_Store;

abstract class Sync_Operation extends Operation_Base {

	/** @return Orchestrator|array error response */
	protected function orchestrator() {
		if (!\aw2\sync\is_source()) {
			return $this->error('This site is not a sync source.', 'Set AWX_SYNC_ROLE to source in wp-config.php.', 'not_source');
		}
		try {
			return new Orchestrator();
		} catch (Sync_Exception $e) {
			return $this->error($e->getMessage(), 'Add AWX_SYNC_TARGET_URL and AWX_SYNC_TOKEN to wp-config.php.', $e->code_slug, $e->details);
		}
	}

	protected function can_edit_run(string $run_id) {
		$run = Manifest_Store::get($run_id);
		if ($run === null) {
			return $this->error('Unknown run.', 'Start with awesome/sync-analyze.', 'run_not_found');
		}
		if (!\current_user_can('edit_post', (int) $run['post_id'])) {
			return $this->error('You cannot edit the post of this run.', '', 'forbidden');
		}
		return true;
	}

	protected function guard(callable $fn) {
		try {
			return $this->success($fn());
		} catch (Sync_Exception $e) {
			return $this->error($e->getMessage(), self::next_step_for($e->code_slug), $e->code_slug, $e->details);
		}
	}

	private static function next_step_for(string $code): string {
		switch ($code) {
			case 'overwrite_required':
				return 'Call awesome/sync-confirm with overwrite=true to replace the target page.';
			case 'conflict':
			case 'edited_on_prod':
				return 'Call awesome/sync-resume to re-plan, then confirm again.';
			case 'protocol_mismatch':
			case 'registry_mismatch':
				return 'Update the Awesome XP plugin on the older site.';
			case 'not_configured':
				return 'Add AWX_SYNC_TARGET_URL and AWX_SYNC_TOKEN to wp-config.php.';
			default:
				return '';
		}
	}
}

class Sync_Analyze extends Sync_Operation {
	public function execute(array $params = []) {
		$post_id = isset($params['post_id']) ? (int) $params['post_id'] : 0;
		if ($post_id <= 0 || !\current_user_can('edit_post', $post_id)) {
			return $this->error('You cannot sync this post.', 'Pass a post_id you may edit.', 'forbidden');
		}
		$o = $this->orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		return $this->guard(function () use ($o, $post_id) {
			return $o->analyze($post_id, \get_current_user_id());
		});
	}
}

class Sync_Plan extends Sync_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->can_edit_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		return $this->guard(function () use ($o, $run_id) {
			return $o->plan($run_id);
		});
	}
}

class Sync_Confirm extends Sync_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->can_edit_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		$overwrite = !empty($params['overwrite']);
		return $this->guard(function () use ($o, $run_id, $overwrite) {
			return $o->confirm($run_id, $overwrite);
		});
	}
}

class Sync_Step extends Sync_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->can_edit_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		$step_id = !empty($params['step_id']) ? (string) $params['step_id'] : null;
		return $this->guard(function () use ($o, $run_id, $step_id) {
			return $o->step($run_id, $step_id);
		});
	}
}

class Sync_Resume extends Sync_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->can_edit_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		return $this->guard(function () use ($o, $run_id) {
			return $o->resume($run_id);
		});
	}
}

class Sync_Cancel extends Sync_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->can_edit_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		return $this->guard(function () use ($run_id) {
			Manifest_Store::update($run_id, array('status' => 'cancelled'));
			return array('run_id' => $run_id, 'status' => 'cancelled');
		});
	}
}

class Sync_Status extends Sync_Operation {
	public function execute(array $params = []) {
		if (!empty($params['run_id'])) {
			$run_id = (string) $params['run_id'];
			$ok = $this->can_edit_run($run_id);
			if ($ok !== true) {
				return $ok;
			}
			$o = $this->orchestrator();
			if (\is_array($o)) {
				return $o;
			}
			return $this->guard(function () use ($o, $run_id) {
				return $o->status($run_id);
			});
		}
		$post_id = isset($params['post_id']) ? (int) $params['post_id'] : 0;
		if ($post_id <= 0 || !\current_user_can('edit_post', $post_id)) {
			return $this->error('You cannot view this post.', '', 'forbidden');
		}
		$open = Manifest_Store::open_for_post($post_id);
		$last = \get_post_meta($post_id, '_awx_last_sync', true);
		return $this->success(array(
			'configured' => \aw2\sync\is_source() && (\defined('AWX_SYNC_TARGET_URL') || \getenv('AWX_SYNC_TARGET_URL')) && (\defined('AWX_SYNC_TOKEN') || \getenv('AWX_SYNC_TOKEN')),
			'target_url' => \defined('AWX_SYNC_TARGET_URL') ? (string) AWX_SYNC_TARGET_URL : (string) \getenv('AWX_SYNC_TARGET_URL'),
			'open_run'   => $open ? array('run_id' => $open['run_id'], 'status' => $open['status'], 'created_at' => $open['created_at'], 'progress' => \aw2\sync\Step_Store::summary($open['run_id'])) : null,
			'last_sync'  => \is_array($last) ? $last : null,
			'recent'     => \array_map(function ($r) { return array('run_id' => $r['run_id'], 'status' => $r['status'], 'created_at' => $r['created_at'], 'error' => $r['error']); }, Manifest_Store::recent_for_post($post_id, 5)),
		));
	}
}

/**
 * Definitions merged into the Abilities registry.
 */
class Sync_Abilities {

	public static function categories(): array {
		return array(
			'awesome-sync' => array('label' => 'Awesome Sync', 'description' => 'Move saved pages, with their media and reusable blocks, from this source site to the configured target site.'),
		);
	}

	public static function abilities(): array {
		$run = array('run_id' => array('type' => 'string', 'description' => 'Run id returned by awesome/sync-analyze'));
		return array(
			'awesome/sync-analyze' => array(
				'label' => 'Sync Analyze', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Analyze::class,
				'description' => 'Reads the saved post, lists its dependencies, performs the handshake with the target and opens a run. Nothing is written on the target.',
				'input_schema' => array('type' => 'object', 'required' => array('post_id'), 'properties' => array('post_id' => array('type' => 'integer', 'description' => 'Post to sync'))),
			),
			'awesome/sync-plan' => array(
				'label' => 'Sync Plan', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Plan::class,
				'description' => 'Asks the target what is missing or changed and returns the dry run: steps, warnings, blockers and the status the target page will end up with. Nothing is written.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run),
			),
			'awesome/sync-confirm' => array(
				'label' => 'Sync Confirm', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Confirm::class,
				'description' => 'Confirms the dry run so steps may be executed. overwrite=true is required when the target page was edited since the last sync.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run + array('overwrite' => array('type' => 'boolean', 'default' => false))),
			),
			'awesome/sync-step' => array(
				'label' => 'Sync Step', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Step::class,
				'description' => 'Executes the next pending step (or a given step_id) of a confirmed run: one media file, one reusable block or the page. Call until status is done.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run + array('step_id' => array('type' => 'string'))),
			),
			'awesome/sync-resume' => array(
				'label' => 'Sync Resume', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Resume::class,
				'description' => 'Re-plans an interrupted or failed run so it can be confirmed and continued without duplicating anything already written.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run),
			),
			'awesome/sync-cancel' => array(
				'label' => 'Sync Cancel', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Cancel::class,
				'description' => 'Cancels a run. Objects already written on the target stay in place.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run),
			),
			'awesome/sync-status' => array(
				'label' => 'Sync Status', 'category' => 'awesome-sync', 'tier' => 'read', 'operation' => Sync_Status::class,
				'description' => 'Status of a run, or of a post: its open run, last sync and recent runs.',
				'input_schema' => array('type' => 'object', 'properties' => $run + array('post_id' => array('type' => 'integer'))),
			),
		);
	}

	public static function register(): void {
		if (!\class_exists('\aw2\abilities\Abilities_Registry')) {
			return;
		}
		$registry = \aw2\abilities\Abilities_Registry::instance();
		if (\method_exists($registry, 'add_abilities')) {
			$registry->add_abilities(self::categories(), self::abilities());
		}
	}
}
