<?php
namespace aw2\abilities\operations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base Operation Class
 *
 * Provides common execution, response formatting, and audit logging.
 */
abstract class Operation_Base
{
    /**
     * Execute the operation with parameters.
     *
     * @param array $params
     * @return array
     */
    abstract public function execute(array $params = []);

    /**
     * Format a success response.
     *
     * @param mixed $data
     * @param string $message
     * @return array
     */
    protected function success($data = [], $message = '')
    {
        $response = [
            'success' => true,
            'data'    => $data
        ];
        if (!empty($message)) {
            $response['message'] = $message;
        }
        return $response;
    }

    /**
     * Format an error response with an actionable next step hint.
     *
     * @param string $message Error message
     * @param string $next_step Actionable next step for the agent
     * @param string $code Error code
     * @param array $details Extra diagnostic details
     * @return array
     */
    protected function error($message, $next_step = '', $code = 'operation_error', array $details = [])
    {
        $response = [
            'success'   => false,
            'error'     => [
                'code'      => $code,
                'message'   => $message,
                'next_step' => $next_step,
                'details'   => $details
            ]
        ];
        return $response;
    }

    /**
     * Record an audit log entry for the operation.
     *
     * @param string $ability Ability name (e.g., 'awesome/block-create')
     * @param string $artifact_type Type ('block', 'service', 'app', 'pattern')
     * @param string $slug Slug of artifact
     * @param string $outcome 'success' | 'failure' | 'rejected'
     * @param array $extra Additional context
     */
    protected function audit_log($ability, $artifact_type, $slug, $outcome, array $extra = [])
    {
        $current_user_id = get_current_user_id();
        $entry = [
            'timestamp'     => current_time('mysql'),
            'user_id'       => $current_user_id,
            'ability'       => $ability,
            'artifact_type' => $artifact_type,
            'slug'          => $slug,
            'outcome'       => $outcome,
            'details'       => $extra
        ];

        // Store recent audit entries in an option or transient (rolling 100 entries)
        $audit_log = get_option('awx_abilities_audit_log', []);
        if (!is_array($audit_log)) {
            $audit_log = [];
        }
        array_unshift($audit_log, $entry);
        if (count($audit_log) > 100) {
            $audit_log = array_slice($audit_log, 0, 100);
        }
        update_option('awx_abilities_audit_log', $audit_log, false);
    }
}
