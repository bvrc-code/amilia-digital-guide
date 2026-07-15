<?php
/**
 * Settings / Guide Builder
 *
 * Admin screen (Settings → Digital Guide) where the season's guide is
 * assembled: pick which Amilia programs to include, order them, give each
 * a display label and an optional hand-written section intro, and set the
 * guide-level title / intro / discount note.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Register settings ──────────────────────────────────────────────────────────
add_action( 'admin_init', 'adg_register_settings' );
function adg_register_settings() {
    register_setting( ADG_OPTION_GROUP, 'adg_base_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => 'https://app.amilia.com/api/v3/en/org/blue-valley/activities?perPage=2000&Page={PAGE}',
    ] );
    register_setting( ADG_OPTION_GROUP, 'adg_programs_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => 'https://app.amilia.com/api/v3/en/org/blue-valley/programs?perPage=200&page={PAGE}',
    ] );
    register_setting( ADG_OPTION_GROUP, 'adg_cache_expiry', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 3600,
    ] );
    register_setting( ADG_OPTION_GROUP, 'adg_guide_config', [
        'type'              => 'array',
        'sanitize_callback' => 'adg_sanitize_guide_config',
        'default'           => [],
    ] );
}

/**
 * Sanitize the Guide Builder config on save.
 *
 * @param  mixed $raw Posted config.
 * @return array
 */
function adg_sanitize_guide_config( $raw ): array {
    if ( ! is_array( $raw ) ) {
        return [];
    }

    $clean = [
        'title'         => isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '',
        'intro'         => isset( $raw['intro'] ) ? wp_kses_post( $raw['intro'] ) : '',
        'discount_note' => isset( $raw['discount_note'] ) ? wp_kses_post( $raw['discount_note'] ) : '',
        'programs'      => [],
    ];

    if ( ! empty( $raw['programs'] ) && is_array( $raw['programs'] ) ) {
        foreach ( $raw['programs'] as $program_id => $row ) {
            $program_id = absint( $program_id );
            if ( ! $program_id || ! is_array( $row ) ) {
                continue;
            }
            // Persist rows the admin touched (label/intro/order) even when
            // unchecked, so their settings survive a season being toggled off.
            $entry = [
                'include' => empty( $row['include'] ) ? 0 : 1,
                'order'   => isset( $row['order'] ) ? absint( $row['order'] ) : 0,
                'label'   => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
                'intro'   => isset( $row['intro'] ) ? wp_kses_post( $row['intro'] ) : '',
            ];
            if ( $entry['include'] || $entry['label'] !== '' || $entry['intro'] !== '' ) {
                $clean['programs'][ $program_id ] = $entry;
            }
        }
    }

    return $clean;
}

/**
 * Get the saved guide config with defaults filled in.
 *
 * @return array
 */
function adg_get_guide_config(): array {
    $config = get_option( 'adg_guide_config', [] );
    if ( ! is_array( $config ) ) {
        $config = [];
    }
    return wp_parse_args( $config, [
        'title'         => '',
        'intro'         => '',
        'discount_note' => 'Blue Valley School District residents receive a 25% discount on most programs. Discounts are applied at registration.',
        'programs'      => [],
    ] );
}

/**
 * Selected programs in display order: [ program_id => ['label' => …, 'intro' => …] ].
 *
 * @return array
 */
function adg_get_selected_programs(): array {
    $config   = adg_get_guide_config();
    $selected = [];

    foreach ( $config['programs'] as $program_id => $row ) {
        if ( empty( $row['include'] ) ) {
            continue;
        }
        $selected[ $program_id ] = [
            'order' => isset( $row['order'] ) ? (int) $row['order'] : 0,
            'label' => $row['label'] ?? '',
            'intro' => $row['intro'] ?? '',
        ];
    }

    uasort( $selected, function ( $a, $b ) {
        return $a['order'] <=> $b['order'];
    } );

    return $selected;
}

/**
 * Default display label for a program: the Amilia name minus the season suffix
 * ("Aquatics (Youth) | Fall 2026" → "Aquatics (Youth)").
 */
function adg_default_program_label( string $program_name ): string {
    $parts = explode( '|', $program_name );
    return trim( $parts[0] );
}

// ── Admin actions: refresh / clear cache buttons ───────────────────────────────
add_action( 'admin_post_adg_cache_action', 'adg_handle_cache_action' );
function adg_handle_cache_action() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.' );
    }
    check_admin_referer( 'adg_cache_action' );

    $do = isset( $_POST['adg_do'] ) ? sanitize_key( $_POST['adg_do'] ) : '';

    if ( $do === 'clear' ) {
        delete_transient( 'adg_activities_cache' );
        delete_transient( 'adg_fetch_cooldown' );
        $notice = 'cache_cleared';
    } elseif ( $do === 'refresh' ) {
        adg_cron_refresh_callback();
        $notice = get_option( 'adg_last_refresh_error' ) ? 'refresh_failed' : 'refreshed';
    } elseif ( $do === 'programs' ) {
        delete_transient( 'adg_programs_cache' );
        $result = adg_get_programs( true );
        $notice = is_wp_error( $result ) ? 'refresh_failed' : 'programs_refreshed';
    } else {
        $notice = '';
    }

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'amilia-digital-guide', 'adg_notice' => $notice ],
        admin_url( 'options-general.php' )
    ) );
    exit;
}

// ── Settings page ──────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'adg_add_settings_page' );
function adg_add_settings_page() {
    add_options_page(
        'Amilia Digital Guide',
        'Digital Guide',
        'manage_options',
        'amilia-digital-guide',
        'adg_render_settings_page'
    );
}

function adg_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $config       = adg_get_guide_config();
    $programs     = adg_get_programs();
    $cache_expiry = (int) get_option( 'adg_cache_expiry', 3600 );
    $last_refresh = get_option( 'adg_last_refresh', '' );
    $last_error   = get_option( 'adg_last_refresh_error', '' );
    $cached       = get_transient( 'adg_activities_cache' );
    $notice       = isset( $_GET['adg_notice'] ) ? sanitize_key( $_GET['adg_notice'] ) : '';

    $notices = [
        'cache_cleared'      => [ 'success', 'Activities cache cleared. It will repopulate on the next page view or cron run.' ],
        'refreshed'          => [ 'success', 'Activity data refreshed from Amilia.' ],
        'programs_refreshed' => [ 'success', 'Program list refreshed from Amilia.' ],
        'refresh_failed'     => [ 'error',   'Refresh failed — see the status box below for the error.' ],
    ];
    ?>
    <div class="wrap">
        <h1>Amilia Digital Guide</h1>

        <?php if ( $notice && isset( $notices[ $notice ] ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> is-dismissible">
                <p><?php echo esc_html( $notices[ $notice ][1] ); ?></p>
            </div>
        <?php endif; ?>

        <p>
            Build the seasonal guide by checking the Amilia programs to include, ordering them, and
            (optionally) writing a section intro for each. Render it with the
            <code>[amilia_digital_guide]</code> shortcode; a single section with
            <code>[amilia_digital_guide program="Aquatics (Youth)"]</code> (display label or Amilia program ID).
        </p>

        <form method="post" action="options.php">
            <?php settings_fields( ADG_OPTION_GROUP ); ?>

            <h2>Guide</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="adg-title">Guide title</label></th>
                    <td>
                        <input type="text" id="adg-title" class="regular-text"
                               name="adg_guide_config[title]"
                               value="<?php echo esc_attr( $config['title'] ); ?>"
                               placeholder="e.g. Fall 2026 Digital Program Guide">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="adg-intro">Guide intro (HTML allowed)</label></th>
                    <td>
                        <textarea id="adg-intro" name="adg_guide_config[intro]" rows="4" class="large-text code"><?php
                            echo esc_textarea( $config['intro'] );
                        ?></textarea>
                        <p class="description">Shown under the title, before the first section. Policies, welcome text, etc.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="adg-discount">Resident discount note (HTML allowed)</label></th>
                    <td>
                        <textarea id="adg-discount" name="adg_guide_config[discount_note]" rows="2" class="large-text code"><?php
                            echo esc_textarea( $config['discount_note'] );
                        ?></textarea>
                        <p class="description">Displayed near prices. Prices shown are the Amilia activity price; the BVSD resident discount is applied at registration.</p>
                    </td>
                </tr>
            </table>

            <h2>Programs in this guide</h2>
            <?php if ( is_wp_error( $programs ) ) : ?>
                <div class="notice notice-error inline">
                    <p>Could not load the program list from Amilia: <?php echo esc_html( $programs->get_error_message() ); ?></p>
                </div>
            <?php elseif ( empty( $programs ) ) : ?>
                <p>No programs returned by the Amilia API.</p>
            <?php else : ?>
                <?php
                // Active (non-archived, currently/future-running) first, alphabetical within each bucket.
                usort( $programs, function ( $a, $b ) {
                    if ( $a['IsArchived'] !== $b['IsArchived'] ) {
                        return $a['IsArchived'] ? 1 : -1;
                    }
                    return strcasecmp( $a['Name'], $b['Name'] );
                } );
                ?>
                <table class="widefat striped" style="max-width:1100px">
                    <thead>
                        <tr>
                            <th style="width:70px">Include</th>
                            <th style="width:60px">Order</th>
                            <th>Amilia program</th>
                            <th style="width:220px">Display label</th>
                            <th>Section intro (HTML allowed)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $programs as $prog ) :
                        $pid   = (int) $prog['Id'];
                        $row   = $config['programs'][ $pid ] ?? [];
                        $label = $row['label'] ?? '';
                        $dates = '';
                        if ( ! empty( $prog['Start'] ) && ! empty( $prog['End'] ) ) {
                            $dates = date_i18n( 'n/j/y', strtotime( $prog['Start'] ) )
                                   . ' – ' . date_i18n( 'n/j/y', strtotime( $prog['End'] ) );
                        }
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox"
                                       name="adg_guide_config[programs][<?php echo $pid; ?>][include]"
                                       value="1" <?php checked( ! empty( $row['include'] ) ); ?>>
                            </td>
                            <td>
                                <input type="number" min="0" style="width:55px"
                                       name="adg_guide_config[programs][<?php echo $pid; ?>][order]"
                                       value="<?php echo esc_attr( $row['order'] ?? 0 ); ?>">
                            </td>
                            <td>
                                <strong><?php echo esc_html( $prog['Name'] ); ?></strong><br>
                                <span class="description">
                                    ID <?php echo $pid; ?><?php echo $dates ? ' · ' . esc_html( $dates ) : ''; ?><?php
                                    echo $prog['IsArchived'] ? ' · <em>archived</em>' : ''; ?>
                                </span>
                            </td>
                            <td>
                                <input type="text" class="regular-text" style="width:100%"
                                       name="adg_guide_config[programs][<?php echo $pid; ?>][label]"
                                       value="<?php echo esc_attr( $label ); ?>"
                                       placeholder="<?php echo esc_attr( adg_default_program_label( $prog['Name'] ) ); ?>">
                            </td>
                            <td>
                                <textarea rows="2" style="width:100%"
                                          name="adg_guide_config[programs][<?php echo $pid; ?>][intro]"><?php
                                    echo esc_textarea( $row['intro'] ?? '' );
                                ?></textarea>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>API &amp; Cache</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="adg-base-url">Activities URL template</label></th>
                    <td>
                        <input type="url" id="adg-base-url" class="large-text code"
                               name="adg_base_url"
                               value="<?php echo esc_attr( get_option( 'adg_base_url', 'https://app.amilia.com/api/v3/en/org/blue-valley/activities?perPage=2000&Page={PAGE}' ) ); ?>">
                        <p class="description">Must contain the <code>{PAGE}</code> placeholder.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="adg-programs-url">Programs URL template</label></th>
                    <td>
                        <input type="url" id="adg-programs-url" class="large-text code"
                               name="adg_programs_url"
                               value="<?php echo esc_attr( get_option( 'adg_programs_url', 'https://app.amilia.com/api/v3/en/org/blue-valley/programs?perPage=200&page={PAGE}' ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="adg-cache-expiry">Cache expiry (seconds)</label></th>
                    <td>
                        <input type="number" id="adg-cache-expiry" min="0"
                               name="adg_cache_expiry"
                               value="<?php echo esc_attr( $cache_expiry ); ?>">
                        <p class="description">0 disables caching (every page view hits the API — not recommended).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Guide' ); ?>
        </form>

        <h2>Status</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Activities cache</th>
                <td><?php echo ( $cached !== false ) ? esc_html( count( $cached ) . ' activities cached' ) : 'empty (will fetch on next view / cron run)'; ?></td>
            </tr>
            <tr>
                <th scope="row">Last successful refresh</th>
                <td><?php echo $last_refresh ? esc_html( $last_refresh ) : '—'; ?></td>
            </tr>
            <?php if ( $last_error ) : ?>
                <tr>
                    <th scope="row">Last refresh error</th>
                    <td><code><?php echo esc_html( $last_error ); ?></code></td>
                </tr>
            <?php endif; ?>
        </table>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
            <?php wp_nonce_field( 'adg_cache_action' ); ?>
            <input type="hidden" name="action" value="adg_cache_action">
            <input type="hidden" name="adg_do" value="refresh">
            <?php submit_button( 'Refresh Now', 'secondary', 'submit', false ); ?>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
            <?php wp_nonce_field( 'adg_cache_action' ); ?>
            <input type="hidden" name="action" value="adg_cache_action">
            <input type="hidden" name="adg_do" value="clear">
            <?php submit_button( 'Clear Cache Now', 'secondary', 'submit', false ); ?>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
            <?php wp_nonce_field( 'adg_cache_action' ); ?>
            <input type="hidden" name="action" value="adg_cache_action">
            <input type="hidden" name="adg_do" value="programs">
            <?php submit_button( 'Refresh Program List', 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}
