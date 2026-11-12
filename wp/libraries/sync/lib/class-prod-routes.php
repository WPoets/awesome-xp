<?php
/**
 * Prod-side REST routes under /wp-json/awx-sync/v1/.
 *
 * Registered only when AWX_SYNC_ROLE is 'target'. Every route is signed
 * (Request_Auth), every write is idempotent on step_id, and errors use real
 * HTTP statuses with {code, message, data:{status, retryable, details}}.
 */
namespace aw2\sync;

class Prod_Routes {

	const NS = 'awx-sync/v1';

	public static function init(): void {
		if (!is_target()) {
			return;
		}
		Service_User::init();
		\add_action('rest_api_init', array(__CLASS__, 'register'));
	}

	public static function register(): void {
		$auth = function (\WP_REST_Request $r) {
			$res = Request_Auth::authenticate($r);
			return \is_wp_error($res) ? $res : true;
		};
		$auth_push = function (\WP_REST_Request $r) {
			$res = Request_Auth::authenticate($r, 'sync', (string) $r->get_param('meta'));
			return \is_wp_error($res) ? $res : true;
		};
		$json = array('type' => 'object');

		\register_rest_route(self::NS, '/handshake', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => self::wrap('handshake', function (\WP_REST_Request $r) {
				return (new Receiver())->handshake(self::params($r));
			}),
		));
		\register_rest_route(self::NS, '/plan', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => self::wrap('plan', function (\WP_REST_Request $r) {
				return (new Receiver())->plan(self::params($r));
			}),
		));
		\register_rest_route(self::NS, '/blocks', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => self::wrap('blocks', function (\WP_REST_Request $r) {
				list($run, $step) = self::ids($r);
				return (new Receiver())->write_block(self::params($r), $run, $step);
			}),
		));
		\register_rest_route(self::NS, '/pages', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => self::wrap('pages', function (\WP_REST_Request $r) {
				list($run, $step) = self::ids($r);
				return (new Receiver())->write_page(self::params($r), $run, $step);
			}),
		));
		\register_rest_route(self::NS, '/media/pull', array(
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => self::wrap('media/pull', function (\WP_REST_Request $r) {
				$p = self::params($r);
				list($run, $step) = self::ids($r);
				return (new Media_Handler())->receive_pull((string) ($p['fetch_url'] ?? ''), (array) ($p['media'] ?? array()), $run, $step);
			}),
		));
		if (Receiver::push_allowed()) {
			\register_rest_route(self::NS, '/media', array(
				'methods'             => 'POST',
				'permission_callback' => $auth_push,
				'callback'            => self::wrap('media', function (\WP_REST_Request $r) {
					$meta = \json_decode((string) $r->get_param('meta'), true);
					if (!\is_array($meta)) {
						throw new Sync_Http_Exception('invalid_payload', 'The meta field must be JSON.', 400);
					}
					$run  = (string) ($meta['run_id'] ?? '');
					$step = (string) ($meta['step_id'] ?? '');
					self::assert_ids($run, $step);
					$files = $r->get_file_params();
					return (new Media_Handler())->receive_push((array) ($files['file'] ?? array()), (array) ($meta['media'] ?? array()), $run, $step);
				}),
			));
		}
		if (Code_Receiver::allowed()) {
			self::register_code_routes();
		}
		\register_rest_route(self::NS, '/runs/(?P<run_id>[0-9a-fA-F-]{36})', array(
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => self::wrap('runs', function (\WP_REST_Request $r) {
				return (new Receiver())->run_status((string) $r->get_param('run_id'));
			}),
		));
	}

	/**
	 * Code deploy routes (Release 2). Only when AWX_SYNC_ALLOW_CODE is true,
	 * and every one needs the `code` token scope: a token that only has `sync`
	 * is refused with 403 scope_denied.
	 */
	private static function register_code_routes(): void {
		$auth_code = function (\WP_REST_Request $r) {
			$res = Request_Auth::authenticate($r, 'code');
			return \is_wp_error($res) ? $res : true;
		};
		\register_rest_route(self::NS, '/code/plan', array(
			'methods'             => 'POST',
			'permission_callback' => $auth_code,
			'callback'            => self::wrap('code/plan', function (\WP_REST_Request $r) {
				return (new Code_Receiver())->plan(self::params($r));
			}),
		));
		\register_rest_route(self::NS, '/code/modules', array(
			'methods'             => 'POST',
			'permission_callback' => $auth_code,
			'callback'            => self::wrap('code/modules', function (\WP_REST_Request $r) {
				list($run, $step) = self::ids($r);
				return (new Code_Receiver())->write_module(self::params($r), $run, $step);
			}),
		));
		\register_rest_route(self::NS, '/code/acf-field-groups', array(
			'methods'             => 'POST',
			'permission_callback' => $auth_code,
			'callback'            => self::wrap('code/acf-field-groups', function (\WP_REST_Request $r) {
				list($run, $step) = self::ids($r);
				return (new Code_Receiver())->write_acf_group(self::params($r), $run, $step);
			}),
		));
		\register_rest_route(self::NS, '/code/options', array(
			'methods'             => 'POST',
			'permission_callback' => $auth_code,
			'callback'            => self::wrap('code/options', function (\WP_REST_Request $r) {
				list($run, $step) = self::ids($r);
				return (new Code_Receiver())->write_option(self::params($r), $run, $step);
			}),
		));
		\register_rest_route(self::NS, '/code/menus', array(
			'methods'             => 'POST',
			'permission_callback' => $auth_code,
			'callback'            => self::wrap('code/menus', function (\WP_REST_Request $r) {
				list($run, $step) = self::ids($r);
				return (new Code_Receiver())->write_menu(self::params($r), $run, $step);
			}),
		));
		\register_rest_route(self::NS, '/code/finalize', array(
			'methods'             => 'POST',
			'permission_callback' => $auth_code,
			'callback'            => self::wrap('code/finalize', function (\WP_REST_Request $r) {
				list($run, $step) = self::ids($r);
				return (new Code_Receiver())->finalize($run, $step);
			}),
		));
	}

	/* ---- helpers ---- */

	private static function params(\WP_REST_Request $r): array {
		$p = $r->get_json_params();
		return \is_array($p) ? $p : array();
	}

	private static function ids(\WP_REST_Request $r): array {
		$p = self::params($r);
		$run  = (string) ($p['run_id'] ?? '');
		$step = (string) ($p['step_id'] ?? '');
		self::assert_ids($run, $step);
		return array($run, $step);
	}

	private static function assert_ids(string $run, string $step): void {
		$re = '/^' . Transformer::UUID_RE . '$/';
		if (!\preg_match($re, $run) || !\preg_match($re, $step)) {
			throw new Sync_Http_Exception('invalid_payload', 'run_id and step_id must be UUIDs.', 400);
		}
	}

	/** Runs the callback, converts exceptions to WP_Error, writes the audit row. */
	private static function wrap(string $route, callable $fn): callable {
		return function (\WP_REST_Request $r) use ($route, $fn) {
			$token_id = (string) $r->get_header('x-awx-token-id');
			$p = self::params($r);
			$run_id = isset($p['run_id']) ? (string) $p['run_id'] : null;
			$uuid = null;
			foreach (array('uuid', 'media.uuid') as $k) {
				$v = $k === 'uuid' ? ($p['uuid'] ?? null) : ($p['media']['uuid'] ?? null);
				if (!empty($v)) {
					$uuid = (string) $v;
					break;
				}
			}
			// Code routes carry no UUID: the audit row holds the item key (post_type:slug).
			if ($uuid === null && isset($p['item']['post_type'], $p['item']['slug']) && \is_scalar($p['item']['post_type']) && \is_scalar($p['item']['slug'])) {
				$uuid = (string) $p['item']['post_type'] . ':' . (string) $p['item']['slug'];
			} elseif ($uuid === null && isset($p['key']) && \is_scalar($p['key']) && \strpos($route, 'code/acf') === 0) {
				$uuid = Code_Receiver::ACF_POST_TYPE . ':' . (string) $p['key'];
			} elseif ($uuid === null && isset($p['option']) && \is_scalar($p['option']) && \strpos($route, 'code/options') === 0) {
				$uuid = 'option:' . (string) $p['option'];
			} elseif ($uuid === null && isset($p['slug']) && \is_scalar($p['slug']) && \strpos($route, 'code/menus') === 0) {
				$uuid = 'nav_menu:' . (string) $p['slug'];
			}
			try {
				$result = $fn($r);
				Audit_Log::record(array('token_id' => $token_id, 'run_id' => $run_id, 'route' => $route, 'method' => $r->get_method(), 'object_uuid' => $uuid, 'result' => 'ok', 'http_status' => 200, 'ip' => Request_Auth::client_ip()));
				return \rest_ensure_response($result);
			} catch (Sync_Http_Exception $e) {
				$kind = $e->status === 409 ? 'conflict' : ($e->status === 422 || $e->status === 400 ? 'invalid' : 'error');
				Audit_Log::record(array('token_id' => $token_id, 'run_id' => $run_id, 'route' => $route, 'method' => $r->get_method(), 'object_uuid' => $uuid, 'result' => $kind, 'http_status' => $e->status, 'message' => $e->code_slug . ': ' . $e->getMessage(), 'ip' => Request_Auth::client_ip()));
				return new \WP_Error('awx_sync_' . $e->code_slug, $e->getMessage(), array('status' => $e->status, 'retryable' => $e->retryable, 'details' => $e->details));
			} catch (\Throwable $e) {
				Audit_Log::record(array('token_id' => $token_id, 'run_id' => $run_id, 'route' => $route, 'method' => $r->get_method(), 'object_uuid' => $uuid, 'result' => 'error', 'http_status' => 500, 'message' => \get_class($e) . ': ' . $e->getMessage(), 'ip' => Request_Auth::client_ip()));
				return new \WP_Error('awx_sync_internal', 'Unexpected error on the target: ' . $e->getMessage(), array('status' => 500, 'retryable' => true));
			}
		};
	}
}
