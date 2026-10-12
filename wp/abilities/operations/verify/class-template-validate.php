<?php
namespace aw2\abilities\operations\verify;

use aw2\abilities\operations\Operation_Base;
use aw2\abilities\guardrails\Template_Linter;
use aw2\abilities\guardrails\Validation_Token;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/template-validate
 *
 * Validates shortcode template code for syntax errors and rule violations.
 * Required before any create or update. Returns a validation token on success.
 */
class Template_Validate extends Operation_Base
{
    public function execute(array $params = [])
    {
        $code = isset($params['code']) ? (string)$params['code'] : '';
        $context = isset($params['context']) ? sanitize_key($params['context']) : 'block';

        if (empty(trim($code))) {
            return $this->error(
                'Template code is required for validation.',
                'Provide {"code": "...", "context": "block"} in parameters.',
                'missing_code'
            );
        }

        $lint_result = Template_Linter::lint($code, $context);

        $validation_token = null;
        if ($lint_result['valid']) {
            $validation_token = Validation_Token::generate($code, $context);
        }

        $response_data = [
            'valid'            => $lint_result['valid'],
            'errors'           => $lint_result['errors'],
            'warnings'         => $lint_result['warnings'],
            'validation_token' => $validation_token
        ];

        if ($lint_result['valid']) {
            return $this->success($response_data, 'Template validation succeeded. Pass this validation_token to authoring abilities.');
        } else {
            return $this->error(
                'Template validation failed with errors.',
                'Address all reported errors and call awesome/template-validate again.',
                'validation_failed',
                $response_data
            );
        }
    }
}
