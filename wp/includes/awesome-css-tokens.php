<?php
/**
 * Awesome CSS Tokens Module
 *
 * Source of truth for all design token defaults.
 * Part of Awesome XP (core/tokens/).
 *
 * Provides:
 * - AWX_Token_Defaults class (token values)
 * - AWX_CSS_Output class (dumps CSS vars to page)
 * - awx_get_token_defaults() global function (API for themes)
 *
 * The Monomyth FSE theme reads from this module via
 * awx_get_token_defaults() and uses the values in its
 * wp_theme_json_data_theme filter. This module does NOT
 * touch theme.json — that's the theme's responsibility.
 *
 * @package AwesomeXP
 * @subpackage Tokens
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AWX_TOKENS_PATH', __DIR__ . '/');

require_once AWX_TOKENS_PATH . 'class-awx-token-defaults.php';
require_once AWX_TOKENS_PATH . 'class-awx-css-output.php';

/**
 * Initialize the tokens module.
 */
function awx_tokens_init()
{
    global $awx_token_defaults;

    $awx_token_defaults = new AWX_Token_Defaults();
    new AWX_CSS_Output($awx_token_defaults);
}
add_action('plugins_loaded', 'awx_tokens_init', 5);

/**
 * Public API: Get the token defaults instance.
 *
 * This is how the Monomyth theme (or any theme) accesses token values.
 * Returns null if tokens module hasn't initialized yet.
 *
 * Usage in theme:
 *   $defaults = awx_get_token_defaults();
 *   if ( $defaults ) {
 *       $tokens = $defaults->get_all();
 *   }
 *
 * @return AWX_Token_Defaults|null
 */
function awx_get_token_defaults()
{
    global $awx_token_defaults;
    return $awx_token_defaults ?? null;
}
