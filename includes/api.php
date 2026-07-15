<?php
/**
 * API Layer
 *
 * Fetches activity and program data from the Amilia API, trims activity
 * records down to the fields the guide renders, and manages the transient
 * cache with a last-known-good backup for outages.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Safety cap on pagination loops (2000 items/page → 40k activities). */
const ADG_MAX_PAGES = 20;

/**
 * Fetch a single page from an Amilia endpoint URL template.
 *
 * @param  int    $page      Page number to request.
 * @param  string $base_url  URL template with {PAGE} placeholder.
 * @return array|WP_Error    Decoded response array or WP_Error on failure.
 */
function adg_fetch_page( int $page, string $base_url ) {
    if ( ! defined( 'AMILIA_API_KEY' ) || empty( AMILIA_API_KEY ) ) {
        return new WP_Error( 'no_api_key', 'AMILIA_API_KEY is not defined in wp-config.php.' );
    }
    if ( ! defined( 'AMILIA_API_SECRET' ) || empty( AMILIA_API_SECRET ) ) {
        return new WP_Error( 'no_api_secret', 'AMILIA_API_SECRET is not defined in wp-config.php.' );
    }

    $url = str_replace( '{PAGE}', $page, $base_url );

    $response = wp_remote_get( $url, [
        'timeout' => 30,
        'headers' => [
            'X-AMILIA-APIKEY'    => AMILIA_API_KEY,
            'X-AMILIA-APISECRET' => AMILIA_API_SECRET,
            'Accept'             => 'application/json',
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        return new WP_Error(
            'http_error',
            sprintf( 'Amilia API returned HTTP %d for page %d.', $http_code, $page )
        );
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'json_error', 'Invalid JSON from Amilia API (page ' . $page . ').' );
    }

    return $data;
}

/**
 * Trim a raw Amilia activity down to the fields the guide renders.
 *
 * The full payload (~5 KB/activity with Forms, Schedules, Keywords, …) would
 * bloat the transient into many megabytes; the guide needs only these fields.
 *
 * @param  array $item Raw activity from the API.
 * @return array       Trimmed activity.
 */
function adg_trim_item( array $item ): array {
    // Unique location names across all schedule blocks (LocationLabel is
    // unreliably empty in practice; Schedules[*].Locations[*].Name is not).
    $locations = [];
    if ( ! empty( $item['Schedules'] ) && is_array( $item['Schedules'] ) ) {
        foreach ( $item['Schedules'] as $schedule ) {
            if ( empty( $schedule['Locations'] ) || ! is_array( $schedule['Locations'] ) ) {
                continue;
            }
            foreach ( $schedule['Locations'] as $loc ) {
                if ( ! empty( $loc['Name'] ) && ! in_array( $loc['Name'], $locations, true ) ) {
                    $locations[] = $loc['Name'];
                }
            }
        }
    }
    if ( empty( $locations ) && ! empty( $item['LocationLabel'] ) ) {
        $locations[] = $item['LocationLabel'];
    }

    $keep = [
        'Id', 'Name', 'Status', 'Url',
        'ProgramId', 'ProgramName', 'CategoryId', 'CategoryName',
        'SubCategoryId', 'SubCategoryName',
        'Description', 'Prerequisite', 'Note', 'AgeSummary',
        'Price', 'DropInPrice', 'DisplayOrder',
        'StartDate', 'EndDate', 'ScheduleSummary',
        'SpotsRemaining', 'HasWaitListEnabled', 'HasDropInEnabled',
    ];

    $trimmed = [];
    foreach ( $keep as $field ) {
        $trimmed[ $field ] = $item[ $field ] ?? null;
    }
    $trimmed['Locations'] = $locations;

    return $trimmed;
}

/**
 * Fetch every activity page, following the Paging.Next cursor.
 *
 * @return array|WP_Error Trimmed items or WP_Error on failure.
 */
function adg_fetch_all_activities() {
    $base_url  = get_option( 'adg_base_url', 'https://app.amilia.com/api/v3/en/org/blue-valley/activities?perPage=2000&Page={PAGE}' );
    $all_items = [];

    for ( $p = 1; $p <= ADG_MAX_PAGES; $p++ ) {
        $data = adg_fetch_page( $p, $base_url );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        if ( empty( $data['Items'] ) || ! is_array( $data['Items'] ) ) {
            break;
        }

        foreach ( $data['Items'] as $item ) {
            $all_items[] = adg_trim_item( $item );
        }

        if ( empty( $data['Paging']['Next'] ) ) {
            break;
        }
    }

    return $all_items;
}

/**
 * WP-Cron callback: refresh the activities cache in the background.
 *
 * On API failure the existing cache/backup is left intact so visitors keep
 * seeing the last good dataset rather than an error.
 */
add_action( 'adg_cron_refresh', 'adg_cron_refresh_callback' );
function adg_cron_refresh_callback() {
    $items = adg_fetch_all_activities();

    if ( is_wp_error( $items ) ) {
        update_option( 'adg_last_refresh_error', $items->get_error_message() );
        return;
    }

    if ( ! empty( $items ) ) {
        $cache_expiry = (int) get_option( 'adg_cache_expiry', 3600 );
        if ( $cache_expiry > 0 ) {
            set_transient( 'adg_activities_cache', $items, $cache_expiry );
        } else {
            // Caching disabled — a 0-expiry set_transient() would create a
            // non-expiring AUTOLOADED option that the read path never uses
            delete_transient( 'adg_activities_cache' );
        }
        update_option( 'adg_activities_backup', $items, 'no' );
        delete_transient( 'adg_fetch_cooldown' );
        update_option( 'adg_last_refresh', current_time( 'mysql' ) );
        delete_option( 'adg_last_refresh_error' );
    }
}

/**
 * Get all (trimmed) activities: transient cache → live fetch → backup fallback.
 *
 * @return array|WP_Error
 */
function adg_get_all_items() {
    $cache_expiry = (int) get_option( 'adg_cache_expiry', 3600 );

    if ( $cache_expiry > 0 ) {
        $cached = get_transient( 'adg_activities_cache' );
        if ( $cached !== false ) {
            return $cached;
        }
    }

    // A recent live fetch failed — don't make every visitor wait through API
    // timeouts again; serve the last-known-good backup until the cooldown ends
    if ( get_transient( 'adg_fetch_cooldown' ) ) {
        $backup = get_option( 'adg_activities_backup', [] );
        if ( ! empty( $backup ) && is_array( $backup ) ) {
            return $backup;
        }
    }

    $items = adg_fetch_all_activities();

    if ( is_wp_error( $items ) ) {
        set_transient( 'adg_fetch_cooldown', 1, 2 * MINUTE_IN_SECONDS );
        $backup = get_option( 'adg_activities_backup', [] );
        if ( ! empty( $backup ) && is_array( $backup ) ) {
            return $backup;
        }
        return $items; // no backup yet — surface the error
    }

    if ( ! empty( $items ) ) {
        if ( $cache_expiry > 0 ) {
            set_transient( 'adg_activities_cache', $items, $cache_expiry );
        }
        update_option( 'adg_activities_backup', $items, 'no' );
    }

    return $items;
}

/**
 * Get the organization's program list (Id, Name, Start, End, Url, PictureUrl).
 *
 * Cached for 24 hours — the program list changes rarely (once a season).
 * Used by the Guide Builder settings screen to offer program checkboxes.
 *
 * @param  bool $refresh True to bust the cache and fetch live.
 * @return array|WP_Error
 */
function adg_get_programs( bool $refresh = false ) {
    if ( ! $refresh ) {
        $cached = get_transient( 'adg_programs_cache' );
        if ( $cached !== false ) {
            return $cached;
        }
    }

    $base_url = get_option( 'adg_programs_url', 'https://app.amilia.com/api/v3/en/org/blue-valley/programs?perPage=200&page={PAGE}' );
    $programs = [];

    for ( $p = 1; $p <= ADG_MAX_PAGES; $p++ ) {
        $data = adg_fetch_page( $p, $base_url );

        if ( is_wp_error( $data ) ) {
            $backup = get_option( 'adg_programs_backup', [] );
            if ( ! empty( $backup ) && is_array( $backup ) ) {
                return $backup;
            }
            return $data;
        }

        $page_items = isset( $data['Items'] ) && is_array( $data['Items'] ) ? $data['Items'] : [];
        if ( empty( $page_items ) ) {
            break;
        }

        foreach ( $page_items as $prog ) {
            $programs[] = [
                'Id'         => $prog['Id'] ?? 0,
                'Name'       => $prog['Name'] ?? '',
                'Start'      => $prog['Start'] ?? '',
                'End'        => $prog['End'] ?? '',
                'IsArchived' => ! empty( $prog['IsArchived'] ),
                'IsVisible'  => ! isset( $prog['IsVisible'] ) || $prog['IsVisible'],
            ];
        }

        if ( empty( $data['Paging']['Next'] ) ) {
            break;
        }
    }

    if ( ! empty( $programs ) ) {
        set_transient( 'adg_programs_cache', $programs, DAY_IN_SECONDS );
        update_option( 'adg_programs_backup', $programs, 'no' );
    }

    return $programs;
}
