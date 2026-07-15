<?php
/**
 * Plugin Name:  Amilia Digital Guide
 * Plugin URI:   https://bluevalleyrec.org
 * Description:  Renders a seasonal digital program guide from Amilia SmartRec activity data via the [amilia_digital_guide] shortcode.
 * Version:      1.1.0
 * Author:       Blue Valley Recreation
 * License:      GPL-2.0-or-later
 * Text Domain:  amilia-digital-guide
 *
 * Requires:     AMILIA_API_KEY and AMILIA_API_SECRET to be defined in wp-config.php
 *               define( 'AMILIA_API_KEY',    'your-api-key-here' );
 *               define( 'AMILIA_API_SECRET', 'your-api-secret-here' );
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access
}

// Plugin constants
define( 'ADG_VERSION',      '1.1.0' );
define( 'ADG_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'ADG_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'ADG_OPTION_GROUP', 'adg_settings' );

// ── Cron: register custom 5-minute interval ───────────────────────────────────
add_filter( 'cron_schedules', 'adg_add_cron_intervals' );
function adg_add_cron_intervals( array $schedules ): array {
    $schedules['adg_five_minutes'] = [
        'interval' => 300,
        'display'  => 'Every 5 Minutes (Amilia Digital Guide)',
    ];
    return $schedules;
}

// ── Cron: auto-schedule on init (handles upgrades without deactivate/reactivate)
add_action( 'init', 'adg_ensure_cron_scheduled' );
function adg_ensure_cron_scheduled() {
    if ( ! wp_next_scheduled( 'adg_cron_refresh' ) ) {
        wp_schedule_event( time(), 'adg_five_minutes', 'adg_cron_refresh' );
    }
}

// Load includes
require_once ADG_PLUGIN_DIR . 'includes/api.php';
require_once ADG_PLUGIN_DIR . 'includes/settings.php';
require_once ADG_PLUGIN_DIR . 'includes/render.php';
require_once ADG_PLUGIN_DIR . 'includes/shortcode.php';

// Activation: set default options
register_activation_hook( __FILE__, 'adg_activate' );
function adg_activate() {
    add_option( 'adg_base_url',     'https://app.amilia.com/api/v3/en/org/blue-valley/activities?perPage=2000&Page={PAGE}' );
    add_option( 'adg_programs_url', 'https://app.amilia.com/api/v3/en/org/blue-valley/programs?perPage=200&page={PAGE}' );
    add_option( 'adg_cache_expiry', 3600 );
    add_option( 'adg_guide_config', [] );
    add_option( 'adg_activities_backup', [], '', 'no' ); // last-known-good dataset — never autoload
    add_option( 'adg_programs_backup',   [], '', 'no' );

    if ( ! wp_next_scheduled( 'adg_cron_refresh' ) ) {
        wp_schedule_event( time(), 'adg_five_minutes', 'adg_cron_refresh' );
    }
}

// Deactivation: clear caches and unschedule cron
register_deactivation_hook( __FILE__, 'adg_deactivate' );
function adg_deactivate() {
    delete_transient( 'adg_activities_cache' );
    delete_transient( 'adg_programs_cache' );
    delete_transient( 'adg_fetch_cooldown' );

    $timestamp = wp_next_scheduled( 'adg_cron_refresh' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'adg_cron_refresh' );
    }
}
