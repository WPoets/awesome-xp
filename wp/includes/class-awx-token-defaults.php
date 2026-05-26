<?php
/**
 * AWX_Token_Defaults
 *
 * Single source of truth for all design token default values.
 * Developers customize this per project.
 *
 * Token values can come from:
 * 1. This file's hardcoded defaults (ships with the module)
 * 2. A tokens.json file in the plugin directory (optional override)
 * 3. wp_options (saved via admin UI / moodboard — future)
 *
 * Priority: wp_options > tokens.json > hardcoded defaults
 *
 * @package AwesomeXP
 * @subpackage Tokens
 */

if (!defined('ABSPATH')) {
    exit;
}

class AWX_Token_Defaults
{

    /** @var string Token namespace prefix */
    const NS = 'awx';

    /** @var array|null Cached token data */
    private $tokens = null;

    /**
     * Get all tokens (cached).
     *
     * @return array Complete token set.
     */
    public function get_all()
    {
        if ($this->tokens !== null) {
            return $this->tokens;
        }

        // Start with hardcoded defaults
        $tokens = $this->get_hardcoded_defaults();

        // Override with tokens.json if it exists
        $json_file = AWX_TOKENS_PATH . 'tokens.json';
        if (file_exists($json_file)) {
            $json = json_decode(file_get_contents($json_file), true);
            if (is_array($json)) {
                $tokens = $this->deep_merge($tokens, $json);
            }
        }

        // Override with saved options (future: admin UI / moodboard)
        $saved = get_option('awx_token_overrides', []);
        if (!empty($saved) && is_array($saved)) {
            $tokens = $this->deep_merge($tokens, $saved);
        }

        /**
         * Filter the final token set.
         * Allows themes or other plugins to modify tokens programmatically.
         *
         * @param array $tokens The complete token set.
         */
        $this->tokens = apply_filters('awx_tokens', $tokens);

        return $this->tokens;
    }

    /**
     * Get a specific token category.
     *
     * @param string $category e.g., 'color', 'space', 'fontSize'
     * @return array
     */
    public function get($category)
    {
        $all = $this->get_all();
        return $all[$category] ?? [];
    }

    /**
     * Hardcoded defaults — the factory settings.
     *
     * Structured to match the tokens.json schema exactly.
     * Developers can edit these directly for per-project setup.
     *
     * @return array
     */
    private function get_hardcoded_defaults()
    {

        if (!defined('AWESOME_CORE_POST_TYPE'))
            return;

        $defaults = array();
        $arr = \aw2_library::get_module(['post_type' => AWESOME_CORE_POST_TYPE], 'tokens');
        if ($arr) {
            $defaults = \aw2_library::module_run(['post_type' => AWESOME_CORE_POST_TYPE], 'tokens');
        }
        return $defaults;

    }

    /**
     * Resolve a color ref like "brand-a.600" to its HSL channel string.
     *
     * @param string $ref e.g., "brand-a.600"
     * @return string HSL channels, e.g., "210 75% 42%"
     */
    public function resolve_color_ref($ref)
    {
        $parts = explode('.', $ref);
        if (count($parts) !== 2) {
            return '0 0% 0%';
        }
        $tokens = $this->get_all();
        return $tokens['color']['primitives'][$parts[0]][$parts[1]] ?? '0 0% 0%';
    }

    /**
     * Resolve a color ref to hex for theme.json.
     *
     * @param string $ref e.g., "brand-a.600"
     * @return string Hex color, e.g., "#1b6bbb"
     */
    public function resolve_color_ref_to_hex($ref)
    {
        $hsl = $this->resolve_color_ref($ref);
        return self::hsl_to_hex($hsl);
    }

    /**
     * Convert HSL channel string "210 75% 42%" to hex "#1b6bbb".
     *
     * @param string $hsl_str
     * @return string
     */
    public static function hsl_to_hex($hsl_str)
    {
        preg_match_all('/[\d.]+/', $hsl_str, $matches);
        if (count($matches[0]) < 3) {
            return '#000000';
        }

        $h = floatval($matches[0][0]) / 360;
        $s = floatval($matches[0][1]) / 100;
        $l = floatval($matches[0][2]) / 100;

        if ($s == 0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = self::hue_to_rgb($p, $q, $h + 1 / 3);
            $g = self::hue_to_rgb($p, $q, $h);
            $b = self::hue_to_rgb($p, $q, $h - 1 / 3);
        }

        return sprintf('#%02x%02x%02x', round($r * 255), round($g * 255), round($b * 255));
    }

    private static function hue_to_rgb($p, $q, $t)
    {
        if ($t < 0)
            $t += 1;
        if ($t > 1)
            $t -= 1;
        if ($t < 1 / 6)
            return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2)
            return $q;
        if ($t < 2 / 3)
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    }

    /**
     * Deep merge arrays (b overrides a).
     */
    private function deep_merge($a, $b)
    {
        foreach ($b as $key => $value) {
            if (is_array($value) && isset($a[$key]) && is_array($a[$key])) {
                $a[$key] = $this->deep_merge($a[$key], $value);
            } else {
                $a[$key] = $value;
            }
        }
        return $a;
    }
}
