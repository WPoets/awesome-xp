<?php
namespace aw2\abilities\operations\debug;

use aw2\abilities\operations\Operation_Base;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability: awesome/log-list
 *
 * Returns recent exceptions from the Awesome error log, filterable by artifact.
 * Use after preview or publish to diagnose failures. PII is redacted.
 */
class Log_List extends Operation_Base
{
    const MAX_LIMIT = 50;

    public function execute(array $params = [])
    {
        global $wpdb;

        $artifact = isset($params['artifact']) ? sanitize_text_field(trim($params['artifact'])) : '';
        $limit = isset($params['limit']) ? min((int)$params['limit'], self::MAX_LIMIT) : 20;
        if ($limit <= 0) {
            $limit = 20;
        }

        $entries = [];

        // 1. Check awesome_exceptions table if it exists
        $table_exists = false;
        $table_name = '';
        if ($wpdb) {
            $table_name = $wpdb->prefix . 'awesome_exceptions';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
            if (!$table_exists) {
                // Check without prefix
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE 'awesome_exceptions'") === 'awesome_exceptions';
                if ($table_exists) {
                    $table_name = 'awesome_exceptions';
                }
            }
        }

        if ($table_exists && $wpdb) {
            $sql = "SELECT ID, location, module, app_name, sc, message, errno, exception_type, errfile, errline, created_at 
                    FROM `{$table_name}`";
            $where = [];
            $values = [];

            if (!empty($artifact)) {
                $where[] = "(module LIKE %s OR app_name LIKE %s OR location LIKE %s)";
                $like = '%' . $wpdb->esc_like($artifact) . '%';
                $values[] = $like;
                $values[] = $like;
                $values[] = $like;
            }

            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY ID DESC LIMIT %d";
            $values[] = $limit;

            $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
            if ($rows) {
                foreach ($rows as $r) {
                    $entries[] = [
                        'id'             => (int)$r['ID'],
                        'location'       => $r['location'] ?? '',
                        'artifact'       => $r['module'] ?: ($r['app_name'] ?? ''),
                        'shortcode'      => $r['sc'] ?? '',
                        'message'        => $this->redact_pii($r['message'] ?? ''),
                        'exception_type' => $r['exception_type'] ?? '',
                        'file'           => basename($r['errfile'] ?? ''),
                        'line'           => (int)($r['errline'] ?? 0),
                        'timestamp'      => $r['created_at'] ?? ''
                    ];
                }
            }
        }

        // 2. Also attach recent abilities audit log failures if table was empty
        if (empty($entries)) {
            $audit_log = get_option('awx_abilities_audit_log', []);
            foreach ($audit_log as $item) {
                if ($item['outcome'] === 'rejected' || $item['outcome'] === 'failure') {
                    $entries[] = [
                        'id'             => 0,
                        'location'       => $item['ability'],
                        'artifact'       => $item['slug'],
                        'message'        => 'Audit Log: ' . json_encode($item['details']),
                        'exception_type' => 'AbilityRejection',
                        'timestamp'      => $item['timestamp']
                    ];
                }
                if (count($entries) >= $limit) {
                    break;
                }
            }
        }

        return $this->success([
            'entries' => $entries,
            'count'   => count($entries),
            'note'    => 'Personal data, tokens, and credentials have been redacted.'
        ]);
    }

    private function redact_pii($text)
    {
        if (empty($text) || !is_string($text)) {
            return '';
        }
        // Redact email addresses
        $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $text);
        // Redact authorization headers / bearer tokens
        $text = preg_replace('/Bearer\s+[a-zA-Z0-9_\-\.]+/i', 'Bearer [REDACTED_TOKEN]', $text);
        // Redact passwords
        $text = preg_replace('/(password|secret|key|token)["\']?\s*[:=]\s*["\']?[^"\',\s]+/i', '$1: [REDACTED]', $text);
        return $text;
    }
}
