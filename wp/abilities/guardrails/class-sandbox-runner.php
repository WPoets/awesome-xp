<?php
namespace aw2\abilities\guardrails;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sandboxed Template Preview Runner
 *
 * Runs template code in an isolated environment with transaction rollback.
 */
class Sandbox_Runner
{
    const MAX_OUTPUT_BYTES = 524288; // 512 KB
    const TIME_LIMIT_SECS = 5;

    /**
     * Preview template rendering with sample data inside a rollback transaction.
     *
     * @param string $code Shortcode template code
     * @param string $context Context ('block', 'service', 'app')
     * @param array $sample_attributes Key-value pairs for sample attributes
     * @return array ['rendered' => string, 'warnings' => array, 'exception' => string|null]
     */
    public static function preview($code, $context = 'block', $sample_attributes = [])
    {
        global $wpdb;

        $rendered = '';
        $exception = null;
        $warnings = [];

        // Save current environment state to restore after preview
        $saved_env = null;
        if (class_exists('\aw2_library')) {
            $saved_env = \aw2_library::$stack;
        }

        // Populate sample attributes into template scope
        if (!empty($sample_attributes) && is_array($sample_attributes)) {
            foreach ($sample_attributes as $k => $v) {
                if (class_exists('\aw2_library') && method_exists('\aw2_library', 'set')) {
                    \aw2_library::set("template.{$k}", $v);
                    \aw2_library::set("content.{$k}", $v);
                    \aw2_library::set("settings.{$k}", $v);
                    \aw2_library::set("style.{$k}", $v);
                }
            }
        }

        // Begin transaction for database safety
        $in_transaction = false;
        try {
            if ($wpdb) {
                $wpdb->query('START TRANSACTION');
                $in_transaction = true;
            }
        } catch (\Throwable $t) {
            $warnings[] = 'Could not start database rollback transaction: ' . $t->getMessage();
        }

        // Run execution inside output buffer
        ob_start();
        $start_time = microtime(true);

        try {
            // Execute template via Awesome parser
            $parsed = '';
            if (class_exists('\aw2_library') && method_exists('\aw2_library', 'parse_shortcode')) {
                $parsed = \aw2_library::parse_shortcode($code);
            } elseif (function_exists('do_shortcode')) {
                $parsed = do_shortcode($code);
            } else {
                $parsed = $code;
            }

            if (!empty($parsed)) {
                echo $parsed;
            }

            $rendered = ob_get_clean();

            // Check output size cap
            if (strlen($rendered) > self::MAX_OUTPUT_BYTES) {
                $rendered = substr($rendered, 0, self::MAX_OUTPUT_BYTES) . "\n<!-- [Truncated: Output exceeded maximum allowed size] -->";
                $warnings[] = 'Output size exceeded maximum limit and was truncated.';
            }

            // Check time cap
            $elapsed = microtime(true) - $start_time;
            if ($elapsed > self::TIME_LIMIT_SECS) {
                $warnings[] = "Execution time ({$elapsed}s) approached safe limit.";
            }

        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $exception = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        } finally {
            // Always rollback database transaction
            if ($in_transaction && $wpdb) {
                try {
                    $wpdb->query('ROLLBACK');
                } catch (\Throwable $rb_err) {
                    // Ignore rollback failure in test environments
                }
            }

            // Restore environment state
            if (class_exists('\aw2_library') && $saved_env !== null && method_exists('\aw2_library', 'reset_env')) {
                \aw2_library::reset_env($saved_env);
            }
        }

        return [
            'rendered' => $rendered,
            'warnings' => $warnings,
            'exception' => $exception
        ];
    }
}
