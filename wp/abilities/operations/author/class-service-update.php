<?php
namespace aw2\abilities\operations\author;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Validation_Token;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/service-update
 *
 * Saves changes to a custom service as a new draft version.
 */
class Service_Update extends Operation_Base
{
    public function execute(array $params = [])
    {
        $raw_name = isset($params['name']) ? sanitize_text_field(trim($params['name'])) : '';
        $changes = isset($params['changes']) && is_array($params['changes']) ? $params['changes'] : $params;
        $validation_token = isset($params['validation_token']) ? (string)$params['validation_token'] : '';
        $if_version = isset($params['if_version']) ? (int)$params['if_version'] : null;

        if (empty($raw_name)) {
            return $this->error('Service name is required.', 'Provide {"name": "custom.my_service"}', 'missing_service_name');
        }

        $name = strpos($raw_name, 'custom.') !== 0 ? 'custom.' . ltrim($raw_name, '.') : $raw_name;

        $custom_drafts = get_option('awx_custom_service_drafts', []);
        $current_service = $custom_drafts[$name] ?? null;

        if (!$current_service) {
            return $this->error("Custom service '{$name}' not found.", "Call awesome/service-create first.", 'service_not_found');
        }

        $current_version = (int)($current_service['version'] ?? 1);
        if ($if_version !== null && $if_version !== $current_version) {
            return $this->error(
                "Version conflict. Expected version {$if_version}, but current version is {$current_version}.",
                "Call awesome/service-get to fetch current state before updating.",
                'version_conflict'
            );
        }

        $code = isset($changes['code']) ? (string)$changes['code'] : ($current_service['code'] ?? '');

        // Validation gate
        if (isset($changes['code'])) {
            if (empty($validation_token) || !Validation_Token::verify($validation_token, $code, 'service')) {
                $this->audit_log('awesome/service-update', 'service', $name, 'rejected', ['reason' => 'invalid_token']);
                return $this->error(
                    'Validation token invalid or missing for updated service code.',
                    'Call awesome/template-validate with context="service" before saving.',
                    'invalid_validation_token'
                );
            }
        }

        $next_version = $current_version + 1;
        $current_service['code'] = $code;
        if (isset($changes['attributes'])) {
            $current_service['attributes'] = $changes['attributes'];
        }
        if (isset($changes['return_type'])) {
            $current_service['return_type'] = $changes['return_type'];
        }
        $current_service['version'] = $next_version;
        $current_service['updated_at'] = current_time('mysql');

        $custom_drafts[$name] = $current_service;
        update_option('awx_custom_service_drafts', $custom_drafts, false);

        $this->audit_log('awesome/service-update', 'service', $name, 'success');

        return $this->success([
            'name'        => $name,
            'version'     => $next_version,
            'status'      => 'draft',
            'message'     => "Custom service '{$name}' updated to draft version {$next_version}."
        ]);
    }
}
