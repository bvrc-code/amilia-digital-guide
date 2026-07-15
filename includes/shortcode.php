<?php
/**
 * Shortcode
 *
 * [amilia_digital_guide]                       — full configured guide with TOC
 * [amilia_digital_guide program="Gymnastics"]  — one section (display label,
 *                                                Amilia program name, or ID)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'amilia_digital_guide', 'adg_shortcode_handler' );

/**
 * Resolve a program="" attribute to [ 'id', 'label', 'intro' ].
 *
 * Accepts a numeric Amilia program ID, a configured display label, or an
 * Amilia program name (with or without the " | Season" suffix). Programs not
 * selected in the Guide Builder are still resolvable — handy for one-off
 * embeds — falling back to the cleaned Amilia name as the label.
 *
 * @param  string $wanted Attribute value.
 * @param  array  $items  Trimmed activities.
 * @return array|null
 */
function adg_resolve_program_attr( string $wanted, array $items ): ?array {
    $wanted   = trim( $wanted );
    $config   = adg_get_guide_config();
    $selected = $config['programs'];

    $make = function ( int $pid, string $fallback_label ) use ( $selected ): array {
        $row = $selected[ $pid ] ?? [];
        return [
            'id'    => $pid,
            'label' => ! empty( $row['label'] ) ? $row['label'] : $fallback_label,
            'intro' => $row['intro'] ?? '',
        ];
    };

    // Program names present in the dataset: [ pid => ProgramName ]
    $names = [];
    foreach ( $items as $item ) {
        $pid = (int) ( $item['ProgramId'] ?? 0 );
        if ( $pid && ! isset( $names[ $pid ] ) && ! empty( $item['ProgramName'] ) ) {
            $names[ $pid ] = $item['ProgramName'];
        }
    }

    if ( ctype_digit( $wanted ) ) {
        $pid = (int) $wanted;
        return $make( $pid, adg_default_program_label( $names[ $pid ] ?? '' ) );
    }

    // Configured display label match
    foreach ( $selected as $pid => $row ) {
        if ( ! empty( $row['label'] ) && strcasecmp( $row['label'], $wanted ) === 0 ) {
            return $make( (int) $pid, $row['label'] );
        }
    }

    // Amilia program name match — full name or season-stripped
    foreach ( $names as $pid => $name ) {
        if ( strcasecmp( $name, $wanted ) === 0
          || strcasecmp( adg_default_program_label( $name ), $wanted ) === 0 ) {
            return $make( (int) $pid, adg_default_program_label( $name ) );
        }
    }

    return null;
}

/**
 * Shortcode handler.
 *
 * @param  array|string $atts Shortcode attributes.
 * @return string
 */
function adg_shortcode_handler( $atts ): string {
    $atts = shortcode_atts( [
        'program' => '',
    ], $atts, 'amilia_digital_guide' );

    $items = adg_get_all_items();

    if ( is_wp_error( $items ) ) {
        if ( current_user_can( 'manage_options' ) ) {
            return '<p class="adg-error"><strong>Amilia Digital Guide error:</strong> '
                 . esc_html( $items->get_error_message() ) . '</p>';
        }
        return '<p class="adg-error">The program guide is temporarily unavailable. Please check back soon.</p>';
    }

    if ( empty( $items ) ) {
        return '<p class="adg-empty">No activities are currently available.</p>';
    }

    $only_program = null;
    if ( $atts['program'] !== '' ) {
        $only_program = adg_resolve_program_attr( $atts['program'], $items );
        if ( $only_program === null ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<p class="adg-error"><strong>Amilia Digital Guide:</strong> program "'
                     . esc_html( $atts['program'] ) . '" not found. Use a display label, Amilia program name, or program ID.</p>';
            }
            return '';
        }
    }

    return adg_render_guide( $items, $only_program );
}
