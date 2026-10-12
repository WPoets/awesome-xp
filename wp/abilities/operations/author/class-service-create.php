<?php
namespace aw2\abilities\operations\author;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Validation_Token;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/service-create
 *
 * Creates a custom service as a draft.
 * Namespaced (custom.*) so it can never shadow core services.
 */
class Service_Create extends Operation_Base
{
    public function execute(array $params = [])
    {
        $raw_name = isset($params['name']) ? sanitize_text_field(trim($params['name'])) : '';
        $code = isset($params['code']) ? (string)$params['code'] : '';
        $attributes = isset($params['attribute_definitions']) && is_array($params['attribute_definitions']) ? $params['attribute_definitions'] : [];
        $return_type = isset($params['return_type']) ? sanitize_text_field($params['return_type']) : 'mixed';
        $validation_token = isset($params['validation_token']) ? (string)$params['validation_token'] : '';

        if (empty($raw_name) || empty($code)) {
            return $this->error('Service name and code are required.', 'Provide {"name": "custom.my_service", "code": "..."}', 'missing_parameters');
        }

        // Force 'custom.' namespace to protect core services
        if (strpos($raw_name, 'custom.') !== 0) {
            $name = 'custom.' . ltrim($raw_name, '.');
        } else {
            $name = $raw_name;
        }

        // Enforce Validation Token Gate
        if (empty($validation_token) || !Validation_Token::verify($validation_token, $code, 'service')) {
            $this->audit_log('awesome/service-create', 'service', $name, 'rejected', ['reason' => 'invalid_token']);
            return $this->error(
                'A valid validation_token matching this exact service code is required.',
                'Call awesome/template-validate with context="service" before saving.',
                'invalid_validation_token'
            );
        }

        // Check if service already exists
        $handlers =& \aw2_library::get_array_ref('handlers');
        if (isset($handlers[$name])) {
            return $this->error(
                "Service '{$name}' already exists.",
                "Call awesome/service-update to modify an existing service.",
                'service_exists'
            );
        }

        $service_data = [
            'name'        => $name,
            'code'        => $code,
            'attributes'  => $attributes,
            'return_type' => $return_type,
            'version'     => 1,
            'status'      => 'draft',
            'created_at'  => current_time('mysql')
        ];

        // Store custom draft in options/transients
        $custom_drafts = get_option('awx_custom_service_drafts', []);
        $custom_drafts[$name] = $service_data;
        update_option('awx_custom_service_drafts', $custom_drafts, false);

        $this->audit_log('awesome/service-create', 'service', $name, 'success');

        return $this->success([
            'name'    => $name,
            'version' => 1,
            'status'  => 'draft',
            'message' => "Service '{$name}' created as draft. Test with awesome/template-preview and publish with awesome/version-publish."
        ]);
    }
}
