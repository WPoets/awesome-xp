<?php
/**
 * AWX_CSS_Output
 *
 * Enqueues CSS custom properties on both frontend and Gutenberg editor.
 *
 * Three CSS blocks, in order:
 *
 * 1. Module-managed tokens (priority 5)
 *    --awx-shadow-*, --awx-radius-*, --awx-duration-*, --awx-leading-*, etc.
 *    These have no WordPress preset equivalent.
 *
 * 2. Alias layer (priority 20 — after WordPress dumps --wp--preset--*)
 *    --awx-color-primary: var(--wp--preset--color--awx-primary);
 *    --awx-font-size-5:   var(--wp--preset--font-size--awx-5);
 *    --awx-space-4:       var(--wp--preset--spacing--awx-4);
 *    Auto-generated from the token set. No manual mapping.
 *
 * 3. Semantic aliases (priority 20, same block as alias layer)
 *    --awx-space-block-padding: var(--awx-space-4);
 *
 * @package AwesomeXP
 * @subpackage Tokens
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AWX_CSS_Output {

    /** @var AWX_Token_Defaults */
    private $defaults;

    public function __construct( AWX_Token_Defaults $defaults ) {
        $this->defaults = $defaults;

        // enqueue_block_assets fires for BOTH frontend and editor
        add_action( 'enqueue_block_assets', [ $this, 'enqueue_module_tokens' ], 5 );
        add_action( 'enqueue_block_assets', [ $this, 'enqueue_alias_layer' ], 20 );
    }

    /**
     * Output 1: Module-managed tokens.
     *
     * These are tokens that WordPress has no preset for.
     * Dumped as --awx-* directly inside @layer tokens.
     */
    public function enqueue_module_tokens() {
        $tokens = $this->defaults->get_all();
        $lines  = [];

        // ── Shadows ──
        if ( ! empty( $tokens['shadow'] ) ) {
            $lines[] = '    /* Shadows */';
            foreach ( $tokens['shadow'] as $slug => $value ) {
                $lines[] = "    --awx-shadow-{$slug}: {$value};";
            }
        }

        // ── Radius ──
        if ( ! empty( $tokens['radius'] ) ) {
            $lines[] = '    /* Radius */';
            foreach ( $tokens['radius'] as $slug => $value ) {
                $lines[] = "    --awx-radius-{$slug}: {$value};";
            }
        }

        // ── Duration ──
        if ( ! empty( $tokens['duration'] ) ) {
            $lines[] = '    /* Duration */';
            foreach ( $tokens['duration'] as $slug => $value ) {
                $lines[] = "    --awx-duration-{$slug}: {$value};";
            }
        }

        // ── Easing ──
        if ( ! empty( $tokens['ease'] ) ) {
            $lines[] = '    /* Easing */';
            foreach ( $tokens['ease'] as $slug => $value ) {
                $lines[] = "    --awx-ease-{$slug}: {$value};";
            }
        }

        // ── Leading (Line Height) ──
        if ( ! empty( $tokens['leading'] ) ) {
            $lines[] = '    /* Line Heights */';
            foreach ( $tokens['leading'] as $slug => $value ) {
                $lines[] = "    --awx-leading-{$slug}: {$value};";
            }
        }

        // ── Tracking (Letter Spacing) ──
        if ( ! empty( $tokens['tracking'] ) ) {
            $lines[] = '    /* Letter Spacing */';
            foreach ( $tokens['tracking'] as $slug => $value ) {
                $lines[] = "    --awx-tracking-{$slug}: {$value};";
            }
        }

        // ── Font Weights ──
        if ( ! empty( $tokens['fontWeight'] ) ) {
            $lines[] = '    /* Font Weights */';
            foreach ( $tokens['fontWeight'] as $slug => $value ) {
                $lines[] = "    --awx-font-weight-{$slug}: {$value};";
            }
        }

        // ── Border Widths ──
        if ( ! empty( $tokens['border'] ) ) {
            $lines[] = '    /* Border Widths */';
            foreach ( $tokens['border'] as $slug => $value ) {
                $lines[] = "    --awx-border-{$slug}: {$value};";
            }
        }

        // ── Z-Index ──
        if ( ! empty( $tokens['z'] ) ) {
            $lines[] = '    /* Z-Index */';
            foreach ( $tokens['z'] as $slug => $value ) {
                $lines[] = "    --awx-z-{$slug}: {$value};";
            }
        }

        // ── Layout Widths (measure) ──
        if ( ! empty( $tokens['width'] ) ) {
            $lines[] = '    /* Layout Widths */';
            foreach ( $tokens['width'] as $slug => $value ) {
                $lines[] = "    --awx-width-{$slug}: {$value};";
            }
        }

        if ( empty( $lines ) ) {
            return;
        }

        $css = "@layer tokens {\n  :root {\n"
             . implode( "\n", $lines )
             . "\n  }\n}\n";

        // Reduced motion override
        $css .= "\n@media (prefers-reduced-motion: reduce) {\n  :root {\n";
        if ( ! empty( $tokens['duration'] ) ) {
            foreach ( $tokens['duration'] as $slug => $value ) {
                $css .= "    --awx-duration-{$slug}: 0ms;\n";
            }
        }
        $css .= "  }\n}";

        wp_register_style( 'awx-module-tokens', false );
        wp_enqueue_style( 'awx-module-tokens' );
        wp_add_inline_style( 'awx-module-tokens', $css );
    }

    /**
     * Output 2: Alias layer + semantic aliases.
     *
     * Maps --wp--preset--* (which includes user overrides) to --awx-*.
     * This runs at priority 20, AFTER WordPress has dumped its preset vars.
     *
     * Auto-generated: reads the token set and builds the mapping.
     * No manual alias file to maintain.
     */
    public function enqueue_alias_layer() {
        $tokens = $this->defaults->get_all();
        $lines  = [];

        // ── Color aliases ──
        if ( ! empty( $tokens['color']['roles']['light'] ) ) {
            $lines[] = '    /* Colors (via Global Styles — user overrides apply) */';
            foreach ( $tokens['color']['roles']['light'] as $slug => $role ) {
                $lines[] = "    --awx-color-{$slug}: var(--wp--preset--color--awx-{$slug});";
            }
        }

        // ── Font size aliases ──
        if ( ! empty( $tokens['fontSize'] ) ) {
            $lines[] = '    /* Font Sizes (via Global Styles — user overrides apply) */';
            foreach ( $tokens['fontSize'] as $slug => $size ) {
                $lines[] = "    --awx-font-size-{$slug}: var(--wp--preset--font-size--awx-{$slug});";
            }
        }

        // ── Font family aliases ──
        if ( ! empty( $tokens['fontFamily'] ) ) {
            $lines[] = '    /* Font Families (via Global Styles — user overrides apply) */';
            foreach ( $tokens['fontFamily'] as $slug => $family ) {
                $lines[] = "    --awx-font-{$slug}: var(--wp--preset--font-family--awx-{$slug});";
            }
        }

        // ── Spacing aliases ──
        if ( ! empty( $tokens['space'] ) ) {
            $lines[] = '    /* Spacing (via Global Styles — user overrides apply) */';
            foreach ( $tokens['space'] as $slug => $space ) {
                $lines[] = "    --awx-space-{$slug}: var(--wp--preset--spacing--awx-{$slug});";
            }
        }

        // ── Layout width aliases ──
        $lines[] = '    /* Layout Widths */';
        $lines[] = '    --awx-width-content: var(--wp--style--global--content-size, 48rem);';
        $lines[] = '    --awx-width-wide: var(--wp--style--global--wide-size, 72rem);';

        // ── Semantic spacing aliases ──
        if ( ! empty( $tokens['spaceAliases'] ) ) {
            $lines[] = '    /* Semantic Spacing Aliases */';
            foreach ( $tokens['spaceAliases'] as $alias => $ref ) {
                $lines[] = "    --awx-space-{$alias}: var(--awx-space-{$ref});";
            }
        }

        $css = "@layer tokens {\n  :root {\n"
             . implode( "\n", $lines )
             . "\n  }\n}";

        wp_register_style( 'awx-alias-layer', false );
        wp_enqueue_style( 'awx-alias-layer' );
        wp_add_inline_style( 'awx-alias-layer', $css );
    }
}
