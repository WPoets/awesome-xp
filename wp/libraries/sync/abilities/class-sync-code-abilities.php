<?php
/**
 * Abilities for code deploys (Dev side). The Deploy Code screen,
 * `wp awx sync deploy` and MCP agents all drive the same Code_Orchestrator.
 */
namespace aw2\sync\abilities;

use aw2\sync\Code_Catalog;
use aw2\sync\Code_Orchestrator;
use aw2\sync\Deploy_Sets;
use aw2\sync\Manifest_Store;
use aw2\sync\Sync_Exception;

abstract class Code_Operation extends Sync_Operation {

	const CAP = 'develop_for_awesomeui';

	/** Developers, or administrators (same rule as the Export Awesome Apps screen). */
	public static function is_developer(): bool {
		return \current_user_can(self::CAP) || \current_user_can('manage_options');
	}

	protected function developer() {
		if (!self::is_developer()) {
			return $this->error('Only Awesome developers can deploy code.', 'Run as a user with the develop_for_awesomeui capability or an administrator (wp ... --user=<login>).', 'forbidden');
		}
		return true;
	}

	/** @return Code_Orchestrator|array */
	protected function code_orchestrator() {
		if (!\aw2\sync\is_source()) {
			return $this->error('This site is not a sync source.', 'Set AWX_SYNC_ROLE to source in wp-config.php.', 'not_source');
		}
		try {
			return new Code_Orchestrator();
		} catch (Sync_Exception $e) {
			return $this->error($e->getMessage(), 'Add AWX_SYNC_TARGET_URL and AWX_SYNC_TOKEN to wp-config.php.', $e->code_slug, $e->details);
		}
	}

	protected function can_use_run(string $run_id) {
		$run = Manifest_Store::get($run_id);
		if ($run === null) {
			return $this->error('Unknown run.', 'Start with awesome/sync-code-analyze.', 'run_not_found');
		}
		if (($run['kind'] ?? 'page') !== 'code') {
			return $this->error('This run is a page sync.', 'Use awesome/sync-step for page runs.', 'bad_state');
		}
		if ((int) $run['user_id'] !== \get_current_user_id() && !\current_user_can('manage_options')) {
			return $this->error('This run belongs to another user.', '', 'forbidden');
		}
		return true;
	}

	protected function guard_code(callable $fn) {
		try {
			return $this->success($fn());
		} catch (Sync_Exception $e) {
			$next = '';
			switch ($e->code_slug) {
				case 'overwrite_required':
					$next = 'Call awesome/sync-code-confirm again with overwrite=true to replace items edited on the target.';
					break;
				case 'invalid_selection':
				case 'empty_selection':
					$next = 'Pass selected_keys taken from the plan rows whose selectable is true.';
					break;
				case 'not_configured':
					$next = 'Add AWX_SYNC_TARGET_URL and AWX_SYNC_TOKEN to wp-config.php.';
					break;
			}
			return $this->error($e->getMessage(), $next, $e->code_slug, $e->details);
		}
	}

	/** Normalise the selection object from JSON/CLI input. */
	public static function clean_selection($raw): array {
		$raw = \is_array($raw) ? $raw : array();
		$list = function ($v) {
			if ($v === '*' || $v === true || $v === 'all') {
				return '*';
			}
			if (\is_string($v) && $v !== '') {
				$v = \array_map('trim', \explode(',', $v));
			}
			if (!\is_array($v)) {
				return null;
			}
			$v = \array_values(\array_filter(\array_map(function ($x) { return \is_scalar($x) ? (string) $x : ''; }, $v), 'strlen'));
			return $v ?: null;
		};
		$sel = array();
		foreach (array('core', 'dgb', 'acf', 'patterns', 'templates', 'styles', 'tokens', 'options', 'menus') as $k) {
			if (isset($raw[$k]) && ($v = $list($raw[$k])) !== null) {
				$sel[$k] = $v;
			}
		}
		if (!empty($raw['services']) && \is_array($raw['services'])) {
			foreach ($raw['services'] as $pt => $v) {
				$pt = \sanitize_key(\is_int($pt) ? (string) $v : (string) $pt);
				$v  = \is_int($pt) ? '*' : $list($v);
				if ($pt !== '' && $v !== null) {
					$sel['services'][$pt] = $v;
				}
			}
		}
		if (!empty($raw['apps']) && \is_array($raw['apps'])) {
			foreach ($raw['apps'] as $slug => $parts) {
				if (\is_int($slug)) {
					$slug = (string) $parts;
					$parts = array('definition' => true, 'config' => '*', 'modules' => '*');
				}
				$slug = \sanitize_key((string) $slug);
				if ($slug === '' || !\is_array($parts)) {
					continue;
				}
				$p = array('definition' => !empty($parts['definition']));
				foreach (array('config', 'modules', 'pages', 'posts') as $c) {
					if (isset($parts[$c]) && $parts[$c] !== false && ($v = $list($parts[$c])) !== null) {
						$p[$c] = $v;
					}
				}
				$sel['apps'][$slug] = $p;
			}
		}
		return $sel;
	}
}

class Sync_Code_Catalog extends Code_Operation {
	public function execute(array $params = []) {
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		return $this->guard_code(function () {
			return array(
				'tree'       => (new Code_Catalog())->build(),
				'sets'       => \array_map(function ($s) { return array('name' => $s['name'], 'selection' => $s['selection'], 'updated_at' => $s['updated_at'], 'last_run_id' => $s['last_run_id']); }, Deploy_Sets::all()),
				'configured' => \aw2\sync\is_source() && (\defined('AWX_SYNC_TARGET_URL') || \getenv('AWX_SYNC_TARGET_URL')) && (\defined('AWX_SYNC_TOKEN') || \getenv('AWX_SYNC_TOKEN')),
				'target_url' => \defined('AWX_SYNC_TARGET_URL') ? (string) AWX_SYNC_TARGET_URL : (string) \getenv('AWX_SYNC_TARGET_URL'),
				'open_run'   => ($o = Manifest_Store::open_code_run(\get_current_user_id())) ? array('run_id' => $o['run_id'], 'status' => $o['status'], 'label' => $o['label'], 'created_at' => $o['created_at']) : null,
				'recent'     => \array_map(function ($r) { return array('run_id' => $r['run_id'], 'status' => $r['status'], 'label' => $r['label'], 'created_at' => $r['created_at'], 'error' => $r['error'], 'summary' => $r['plan']['summary'] ?? null); }, Manifest_Store::recent_code_runs(10)),
			);
		});
	}
}

class Sync_Code_Analyze extends Code_Operation {
	public function execute(array $params = []) {
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->code_orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		$selection = self::clean_selection($params['selection'] ?? array());
		if (!empty($params['set'])) {
			$set = Deploy_Sets::get((string) $params['set']);
			if ($set === null) {
				return $this->error('Unknown deployment set.', 'Call awesome/sync-set-list.', 'set_not_found');
			}
			$selection = self::clean_selection($set['selection']);
		}
		$label = \sanitize_text_field((string) ($params['label'] ?? ($params['set'] ?? '')));
		$plan_now = !isset($params['plan']) || !empty($params['plan']);
		return $this->guard_code(function () use ($o, $selection, $label, $plan_now) {
			$a = $o->analyze_selection($selection, \get_current_user_id(), $label);
			if ($plan_now) {
				return $o->plan($a['run_id']) + array('analyze' => $a);
			}
			return $a;
		});
	}
}

class Sync_Code_Plan extends Code_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		$ok = $this->can_use_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->code_orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		return $this->guard_code(function () use ($o, $run_id) {
			return $o->plan($run_id);
		});
	}
}

class Sync_Code_Confirm extends Code_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		$ok = $this->can_use_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->code_orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		$keys = \array_map('strval', (array) ($params['selected_keys'] ?? array()));
		$overwrite = !empty($params['overwrite']);
		return $this->guard_code(function () use ($o, $run_id, $keys, $overwrite) {
			return $o->confirm_selection($run_id, $keys, $overwrite);
		});
	}
}

class Sync_Code_Step extends Code_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		$ok = $this->can_use_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->code_orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		$step_id = !empty($params['step_id']) ? (string) $params['step_id'] : null;
		return $this->guard_code(function () use ($o, $run_id, $step_id) {
			return $o->step($run_id, $step_id);
		});
	}
}

class Sync_Code_Resume extends Code_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		$ok = $this->can_use_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->code_orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		return $this->guard_code(function () use ($o, $run_id) {
			return $o->resume($run_id);
		});
	}
}

class Sync_Code_Cancel extends Code_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		$ok = $this->can_use_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		return $this->guard_code(function () use ($run_id) {
			Manifest_Store::update($run_id, array('status' => 'cancelled'));
			return array('run_id' => $run_id, 'status' => 'cancelled');
		});
	}
}

class Sync_Code_Status extends Code_Operation {
	public function execute(array $params = []) {
		$run_id = (string) ($params['run_id'] ?? '');
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		$ok = $this->can_use_run($run_id);
		if ($ok !== true) {
			return $ok;
		}
		$o = $this->code_orchestrator();
		if (\is_array($o)) {
			return $o;
		}
		return $this->guard_code(function () use ($o, $run_id) {
			return $o->view($run_id);
		});
	}
}

class Sync_Set_List extends Code_Operation {
	public function execute(array $params = []) {
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		return $this->success(array('sets' => Deploy_Sets::all()));
	}
}

class Sync_Set_Save extends Code_Operation {
	public function execute(array $params = []) {
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		$name = (string) ($params['name'] ?? '');
		$selection = self::clean_selection($params['selection'] ?? array());
		if (empty($selection)) {
			return $this->error('The selection is empty.', 'Pass a selection object (core, services, apps, dgb, acf, patterns, templates).', 'empty_selection');
		}
		return $this->guard_code(function () use ($name, $selection) {
			return array('set' => Deploy_Sets::save($name, $selection, \get_current_user_id()));
		});
	}
}

class Sync_Set_Delete extends Code_Operation {
	public function execute(array $params = []) {
		$ok = $this->developer();
		if ($ok !== true) {
			return $ok;
		}
		$name = (string) ($params['name'] ?? '');
		return $this->success(array('deleted' => Deploy_Sets::delete($name), 'name' => Deploy_Sets::clean_name($name)));
	}
}

class Sync_Code_Abilities {

	public static function abilities(): array {
		$run = array('run_id' => array('type' => 'string', 'description' => 'Run id returned by awesome/sync-code-analyze'));
		$selection = array('type' => 'object', 'description' => 'What to deploy: {core:"*"|[slug], services:{post_type:"*"|[slug]}, apps:{slug:{definition:bool, config:"*"|[slug], modules:"*"|[slug], pages:"*"|[post_id], posts:"*"|[post_id]}}, dgb:"*"|[slug], acf:"*"|[group key], patterns:"*"|[pattern slug], templates:"*"|[template slug]}');
		return array(
			'awesome/sync-code-catalog' => array(
				'label' => 'Code Catalog', 'category' => 'awesome-sync', 'tier' => 'read', 'operation' => Sync_Code_Catalog::class,
				'description' => 'The deployable code on this site as a tree (core, services, apps, block definitions, ACF, patterns, templates) with hashes and last deploy, plus saved deployment sets and recent runs.',
				'input_schema' => array('type' => 'object', 'properties' => array()),
			),
			'awesome/sync-code-analyze' => array(
				'label' => 'Code Analyze', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Code_Analyze::class,
				'description' => 'Expands a selection (or a saved set) into items, performs the handshake, opens a code-deploy run and, unless plan=false, returns the plan: one row per item with status new/changed/unchanged/prod_edited/only_on_prod/blocked and default ticks. Nothing is written on the target.',
				'input_schema' => array('type' => 'object', 'properties' => array('selection' => $selection, 'set' => array('type' => 'string', 'description' => 'Name of a saved deployment set (overrides selection)'), 'label' => array('type' => 'string'), 'plan' => array('type' => 'boolean', 'default' => true))),
			),
			'awesome/sync-code-plan' => array(
				'label' => 'Code Plan', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Code_Plan::class,
				'description' => 'Re-asks the target for the diff of an open code-deploy run. Nothing is written.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run),
			),
			'awesome/sync-code-confirm' => array(
				'label' => 'Code Confirm', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Code_Confirm::class,
				'description' => 'Confirms which plan rows to deploy (selected_keys) and builds the steps. overwrite=true is required when a ticked row was edited on the target.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id', 'selected_keys'), 'properties' => $run + array('selected_keys' => array('type' => 'array', 'items' => array('type' => 'string')), 'overwrite' => array('type' => 'boolean', 'default' => false))),
			),
			'awesome/sync-code-step' => array(
				'label' => 'Code Step', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Code_Step::class,
				'description' => 'Executes the next pending step (or step_id) of a confirmed code-deploy run: one module, field group, media file, block, page, or the final cache flush. Call until status is done.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run + array('step_id' => array('type' => 'string'))),
			),
			'awesome/sync-code-resume' => array(
				'label' => 'Code Resume', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Code_Resume::class,
				'description' => 'Re-plans an interrupted or failed code-deploy run so it can be confirmed and continued; items already written show as unchanged.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run),
			),
			'awesome/sync-code-cancel' => array(
				'label' => 'Code Cancel', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Code_Cancel::class,
				'description' => 'Cancels a code-deploy run. Items already written on the target stay in place.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run),
			),
			'awesome/sync-code-status' => array(
				'label' => 'Code Status', 'category' => 'awesome-sync', 'tier' => 'read', 'operation' => Sync_Code_Status::class,
				'description' => 'Status, plan rows, steps and progress of a code-deploy run.',
				'input_schema' => array('type' => 'object', 'required' => array('run_id'), 'properties' => $run),
			),
			'awesome/sync-set-list' => array(
				'label' => 'Deploy Set List', 'category' => 'awesome-sync', 'tier' => 'read', 'operation' => Sync_Set_List::class,
				'description' => 'Saved deployment sets (named selections).',
				'input_schema' => array('type' => 'object', 'properties' => array()),
			),
			'awesome/sync-set-save' => array(
				'label' => 'Deploy Set Save', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Set_Save::class,
				'description' => 'Creates or replaces a named deployment set.',
				'input_schema' => array('type' => 'object', 'required' => array('name', 'selection'), 'properties' => array('name' => array('type' => 'string'), 'selection' => $selection)),
			),
			'awesome/sync-set-delete' => array(
				'label' => 'Deploy Set Delete', 'category' => 'awesome-sync', 'tier' => 'publish', 'operation' => Sync_Set_Delete::class,
				'description' => 'Deletes a named deployment set.',
				'input_schema' => array('type' => 'object', 'required' => array('name'), 'properties' => array('name' => array('type' => 'string'))),
			),
		);
	}
}
