<?php
namespace aw2\abilities\operations\verify;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Sandbox_Runner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/template-preview
 *
 * Renders template code with sample data without saving anything.
 * Use after validation to check output.
 */
class Template_Preview extends Operation_Base
{
    public function execute(array $params = [])
    {
        $code = isset($params['code']) ? (string)$params['code'] : '';
        $context = isset($params['context']) ? sanitize_key($params['context']) : 'block';
        $sample_attributes = isset($params['sample_attributes']) && is_array($params['sample_attributes']) ? $params['sample_attributes'] : [];

        if (empty(trim($code))) {
            return $this->error('Template code is required for preview.', 'Provide {"code": "...", "sample_attributes": {...}}', 'missing_code');
        }

        $result = Sandbox_Runner::preview($code, $context, $sample_attributes);

        if (!empty($result['exception'])) {
            return $this->error(
                "Execution error during preview: {$result['exception']}",
                "Review the template shortcodes and call awesome/template-validate to verify syntax.",
                'preview_exception',
                $result
            );
        }

        return $this->success([
            'rendered_html' => $result['rendered'],
            'warnings'      => $result['warnings']
        ]);
    }
}
