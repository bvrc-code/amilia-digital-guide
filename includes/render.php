<?php
/**
 * Guide Renderer
 *
 * Groups activities Program → Category → SubCategory and renders the digital
 * guide: table of contents, per-program sections with admin-written intros,
 * class blurbs (shared description / ages / prerequisites / notes), and a
 * session table per class with schedule, dates, location, price, openings,
 * and a Register link. Inline CSS/JS (sibling-plugin convention) including a
 * print window for "Download PDF".
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Strip a trailing season suffix from a category/subcategory name
 * ("Three's (Age 3) | Summer" → "Three's (Age 3)").
 */
function adg_clean_heading( string $name ): string {
    $parts = explode( '|', $name );
    return trim( $parts[0] );
}

/**
 * Sanitize an Amilia rich-text description for guide display.
 *
 * Amilia descriptions carry ad-hoc inline styling (colors, fonts) that fights
 * the guide's typography — allow structural tags only, no style attributes.
 */
function adg_sanitize_rich_text( ?string $html ): string {
    if ( $html === null || trim( $html ) === '' ) {
        return '';
    }
    $allowed = [
        'p'      => [],
        'br'     => [],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
        'u'      => [],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
        'span'   => [],
        'a'      => [ 'href' => true, 'target' => true, 'rel' => true ],
        'h4'     => [],
        'h5'     => [],
        'h6'     => [],
    ];
    return wp_kses( $html, $allowed );
}

/** Format an activity's date span compactly ("10/6 – 12/22/26"). */
function adg_format_date_range( ?string $start, ?string $end ): string {
    $start_ts = $start ? strtotime( $start ) : false;
    $end_ts   = $end ? strtotime( $end ) : false;
    if ( ! $start_ts && ! $end_ts ) {
        return '';
    }
    if ( $start_ts && $end_ts ) {
        if ( date( 'Y-m-d', $start_ts ) === date( 'Y-m-d', $end_ts ) ) {
            return date_i18n( 'n/j/y', $start_ts );
        }
        return date_i18n( 'n/j', $start_ts ) . ' – ' . date_i18n( 'n/j/y', $end_ts );
    }
    return date_i18n( 'n/j/y', $start_ts ?: $end_ts );
}

/** Format a price: "Free" for 0, otherwise $ with cents only when present. */
function adg_format_price( $price ): string {
    $price = (float) $price;
    if ( $price <= 0 ) {
        return 'Free';
    }
    $formatted = number_format_i18n( $price, 2 );
    if ( substr( $formatted, -3 ) === '.00' ) {
        $formatted = substr( $formatted, 0, -3 );
    }
    return '$' . $formatted;
}

/** Availability label from SpotsRemaining / HasWaitListEnabled. */
function adg_availability_label( array $item ): string {
    $spots = $item['SpotsRemaining'];
    if ( $spots === null || $spots === '' ) {
        return '';
    }
    $spots = (int) $spots;
    if ( $spots > 0 ) {
        return $spots . ' open';
    }
    return ! empty( $item['HasWaitListEnabled'] ) ? 'Full · waitlist' : 'Full';
}

/**
 * Group Normal-status activities of one program into
 * category (first-seen order) → subcategory (first-seen order) → sessions.
 *
 * @param  array $items Trimmed activities, already filtered to one ProgramId.
 * @return array [ catName => [ subName => [ items… ] ] ]
 */
function adg_group_program_items( array $items ): array {
    $grouped = [];

    foreach ( $items as $item ) {
        $cat = $item['CategoryName'] ?: 'Activities';
        $sub = $item['SubCategoryName'] ?: ( $item['Name'] ?? '' );
        $grouped[ $cat ][ $sub ][] = $item;
    }

    // Sessions in chronological order within each class
    foreach ( $grouped as &$subs ) {
        foreach ( $subs as &$sessions ) {
            usort( $sessions, function ( $a, $b ) {
                $cmp = strcmp( (string) $a['StartDate'], (string) $b['StartDate'] );
                return $cmp !== 0 ? $cmp : strcasecmp( (string) $a['Name'], (string) $b['Name'] );
            } );
        }
    }

    return $grouped;
}

/** First non-empty value of a field across a class's session activities. */
function adg_first_field( array $sessions, string $field ): string {
    foreach ( $sessions as $item ) {
        if ( isset( $item[ $field ] ) && trim( (string) $item[ $field ] ) !== '' ) {
            return (string) $item[ $field ];
        }
    }
    return '';
}

/**
 * Render the digital guide.
 *
 * @param  array      $items          All trimmed activities (any program, any status).
 * @param  array|null $only_program   Optional single section: [ 'id' => int, 'label' => string, 'intro' => string ].
 * @return string
 */
function adg_render_guide( array $items, ?array $only_program = null ): string {
    static $instance = 0;
    $instance++;
    $gid = 'adg-' . $instance;

    $config = adg_get_guide_config();

    // Sections to render, in order: [ program_id => ['label','intro'] ]
    if ( $only_program !== null ) {
        $sections = [
            (int) $only_program['id'] => [
                'label' => $only_program['label'],
                'intro' => $only_program['intro'],
            ],
        ];
    } else {
        $sections = adg_get_selected_programs();
        if ( empty( $sections ) ) {
            return '<p class="adg-empty">The digital guide has not been configured yet. '
                 . 'Select programs under Settings → Digital Guide.</p>';
        }
    }

    // Bucket Normal activities by ProgramId, keeping only wanted programs
    $by_program = [];
    foreach ( $items as $item ) {
        if ( ( $item['Status'] ?? '' ) !== 'Normal' ) {
            continue;
        }
        $pid = (int) ( $item['ProgramId'] ?? 0 );
        if ( isset( $sections[ $pid ] ) ) {
            $by_program[ $pid ][] = $item;
        }
    }

    $full_guide = ( $only_program === null );

    ob_start();
    ?>
    <div class="adg-guide" id="<?php echo esc_attr( $gid ); ?>">

        <?php if ( $full_guide ) : ?>
            <div class="adg-header">
                <?php if ( $config['title'] !== '' ) : ?>
                    <h2 class="adg-title"><?php echo esc_html( $config['title'] ); ?></h2>
                <?php endif; ?>
                <button type="button" class="adg-print-btn adg-no-print">Download / Print PDF</button>
            </div>
            <?php if ( $config['intro'] !== '' ) : ?>
                <div class="adg-intro"><?php echo wp_kses_post( $config['intro'] ); ?></div>
            <?php endif; ?>
        <?php else : ?>
            <div class="adg-header">
                <span></span>
                <button type="button" class="adg-print-btn adg-no-print">Download / Print PDF</button>
            </div>
        <?php endif; ?>

        <?php if ( $config['discount_note'] !== '' ) : ?>
            <div class="adg-discount-note"><?php echo wp_kses_post( $config['discount_note'] ); ?></div>
        <?php endif; ?>

        <?php if ( $full_guide && count( $sections ) > 1 ) : ?>
            <nav class="adg-toc adg-no-print" aria-label="Guide sections">
                <strong>In this guide:</strong>
                <ul>
                    <?php foreach ( $sections as $pid => $sec ) :
                        if ( empty( $by_program[ $pid ] ) ) continue;
                        $label = $sec['label'] !== '' ? $sec['label']
                               : adg_default_program_label( $by_program[ $pid ][0]['ProgramName'] ?? '' );
                        ?>
                        <li><a href="#<?php echo esc_attr( $gid . '-sec-' . $pid ); ?>"><?php echo esc_html( $label ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>

        <?php foreach ( $sections as $pid => $sec ) : ?>
            <?php
            if ( empty( $by_program[ $pid ] ) ) {
                continue; // nothing visible in this program right now
            }
            $label = $sec['label'] !== '' ? $sec['label']
                   : adg_default_program_label( $by_program[ $pid ][0]['ProgramName'] ?? '' );
            $grouped = adg_group_program_items( $by_program[ $pid ] );
            ?>
            <section class="adg-section" id="<?php echo esc_attr( $gid . '-sec-' . $pid ); ?>">
                <h2 class="adg-section-title"><?php echo esc_html( $label ); ?></h2>

                <?php if ( ! empty( $sec['intro'] ) ) : ?>
                    <div class="adg-section-intro"><?php echo wp_kses_post( $sec['intro'] ); ?></div>
                <?php endif; ?>

                <?php foreach ( $grouped as $cat_name => $subs ) : ?>
                    <div class="adg-category">
                        <?php if ( adg_clean_heading( $cat_name ) !== $label ) : ?>
                            <h3 class="adg-category-title"><?php echo esc_html( adg_clean_heading( $cat_name ) ); ?></h3>
                        <?php endif; ?>

                        <?php foreach ( $subs as $sub_name => $sessions ) :
                            $description  = adg_sanitize_rich_text( adg_first_field( $sessions, 'Description' ) );
                            $prerequisite = adg_first_field( $sessions, 'Prerequisite' );
                            $note         = adg_first_field( $sessions, 'Note' );
                            $ages         = adg_first_field( $sessions, 'AgeSummary' );
                            ?>
                            <div class="adg-class">
                                <h4 class="adg-class-title"><?php echo esc_html( adg_clean_heading( $sub_name ) ); ?></h4>

                                <?php if ( $ages !== '' ) : ?>
                                    <p class="adg-class-meta"><strong>Ages:</strong> <?php echo esc_html( $ages ); ?></p>
                                <?php endif; ?>

                                <?php if ( $description !== '' ) : ?>
                                    <div class="adg-class-desc"><?php echo $description; ?></div>
                                <?php endif; ?>

                                <?php if ( $prerequisite !== '' ) : ?>
                                    <p class="adg-class-meta"><strong>Prerequisites:</strong> <?php echo esc_html( wp_strip_all_tags( $prerequisite ) ); ?></p>
                                <?php endif; ?>

                                <?php if ( $note !== '' ) : ?>
                                    <p class="adg-class-meta"><strong>Notes:</strong> <?php echo esc_html( wp_strip_all_tags( $note ) ); ?></p>
                                <?php endif; ?>

                                <table class="adg-sessions">
                                    <thead>
                                        <tr>
                                            <th>Session</th>
                                            <th>Days &amp; Times</th>
                                            <th>Dates</th>
                                            <th>Location</th>
                                            <th>Price</th>
                                            <th>Openings</th>
                                            <th class="adg-no-print"><span class="screen-reader-text">Register</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $sessions as $item ) :
                                            $schedule = (string) ( $item['ScheduleSummary'] ?? '' );
                                            if ( function_exists( 'mb_strimwidth' ) ) {
                                                $schedule_display = mb_strimwidth( $schedule, 0, 140, '…' );
                                            } else {
                                                $schedule_display = strlen( $schedule ) > 140 ? substr( $schedule, 0, 139 ) . '…' : $schedule;
                                            }
                                            $availability = adg_availability_label( $item );
                                            ?>
                                            <tr>
                                                <td data-label="Session"><?php echo esc_html( $item['Name'] ?? '' ); ?></td>
                                                <td data-label="Days &amp; Times" title="<?php echo esc_attr( $schedule ); ?>"><?php echo esc_html( $schedule_display ); ?></td>
                                                <td data-label="Dates"><?php echo esc_html( adg_format_date_range( $item['StartDate'] ?? null, $item['EndDate'] ?? null ) ); ?></td>
                                                <td data-label="Location"><?php echo esc_html( implode( ', ', (array) ( $item['Locations'] ?? [] ) ) ); ?></td>
                                                <td data-label="Price"><?php echo esc_html( adg_format_price( $item['Price'] ?? 0 ) ); ?></td>
                                                <td data-label="Openings"><?php echo esc_html( $availability ); ?></td>
                                                <td data-label="" class="adg-no-print">
                                                    <?php if ( ! empty( $item['Url'] ) ) : ?>
                                                        <a class="adg-register" href="<?php echo esc_url( $item['Url'] ); ?>"
                                                           target="_blank" rel="noopener noreferrer">Register</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ( $full_guide && count( $sections ) > 1 ) : ?>
                    <p class="adg-backtotop adg-no-print"><a href="#<?php echo esc_attr( $gid ); ?>">↑ Back to top</a></p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

    </div>
    <?php
    adg_output_assets();
    return ob_get_clean();
}

/**
 * Output the guide's CSS + JS once per page (all instances share them).
 */
function adg_output_assets() {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;
    ?>
    <style id="adg-style">
    .adg-guide { line-height: 1.5; }
    .adg-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .adg-title { margin: 0.2em 0; }
    .adg-print-btn {
        background: #14284b; color: #fff; border: none; border-radius: 4px;
        padding: 8px 16px; font-size: 14px; cursor: pointer;
    }
    .adg-print-btn:hover { background: #1e3a6d; }
    .adg-discount-note {
        background: #f0f5ff; border-left: 4px solid #14284b; padding: 10px 14px;
        margin: 14px 0; font-size: 0.95em;
    }
    .adg-toc { background: #f7f7f7; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px 16px; margin: 14px 0; }
    .adg-toc ul { display: flex; flex-wrap: wrap; gap: 4px 18px; list-style: none; margin: 6px 0 0; padding: 0; }
    .adg-toc li { margin: 0; }
    .adg-section { margin: 34px 0; }
    .adg-section-title {
        background: #14284b; color: #fff; padding: 8px 14px; border-radius: 4px;
        margin: 0 0 12px; font-size: 1.4em;
    }
    .adg-section-intro { margin-bottom: 14px; }
    .adg-category-title { border-bottom: 2px solid #14284b; padding-bottom: 4px; margin: 26px 0 8px; }
    .adg-class { margin: 18px 0 26px; }
    .adg-class-title { margin: 14px 0 4px; font-size: 1.1em; }
    .adg-class-meta { margin: 2px 0; font-size: 0.95em; }
    .adg-class-desc { margin: 4px 0 8px; }
    .adg-class-desc p { margin: 0 0 8px; }
    table.adg-sessions { width: 100%; border-collapse: collapse; margin: 8px 0 0; font-size: 0.93em; }
    .adg-sessions th, .adg-sessions td { border: 1px solid #d9d9d9; padding: 6px 9px; text-align: left; vertical-align: top; }
    .adg-sessions thead th { background: #eef1f6; }
    .adg-sessions tbody tr:nth-child(even) { background: #fafbfd; }
    a.adg-register {
        display: inline-block; background: #14284b; color: #fff !important; text-decoration: none;
        padding: 3px 10px; border-radius: 3px; font-size: 0.9em; white-space: nowrap;
    }
    a.adg-register:hover { background: #1e3a6d; }
    .adg-backtotop { text-align: right; font-size: 0.9em; }
    @media (max-width: 760px) {
        table.adg-sessions, .adg-sessions thead, .adg-sessions tbody, .adg-sessions tr, .adg-sessions th, .adg-sessions td { display: block; }
        .adg-sessions thead { display: none; }
        .adg-sessions tr { border: 1px solid #d9d9d9; margin-bottom: 8px; }
        .adg-sessions td { border: none; padding: 4px 9px; }
        .adg-sessions td[data-label]:not([data-label=""])::before {
            content: attr(data-label) ": "; font-weight: 600;
        }
    }
    @media print {
        .adg-no-print { display: none !important; }
        .adg-section { page-break-before: always; margin: 0 0 20px; }
        .adg-section:first-of-type { page-break-before: auto; }
        .adg-class { page-break-inside: avoid; }
        .adg-sessions td, .adg-sessions th { padding: 3px 6px; }
    }
    </style>
    <script id="adg-script">
    (function () {
        'use strict';
        document.querySelectorAll('.adg-guide .adg-print-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var guide = btn.closest('.adg-guide');
                var style = document.getElementById('adg-style');
                if (!guide || !style) { window.print(); return; }
                // Print in a bare window so the theme's header/nav/sidebars
                // never appear in the PDF.
                var w = window.open('', '_blank');
                if (!w) { window.print(); return; }
                var doc = w.document;
                doc.open();
                doc.write('<!doctype html><html><head><meta charset="utf-8"><title>' +
                    (document.title || 'Program Guide') + '</title></head><body></body></html>');
                doc.close();
                var styleEl = doc.createElement('style');
                styleEl.textContent = style.textContent +
                    '\nbody{font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;margin:24px;}' +
                    '\n.adg-no-print{display:none !important;}';
                doc.head.appendChild(styleEl);
                doc.body.appendChild(doc.importNode(guide, true));
                w.focus();
                // Give the new window a beat to lay out before printing
                setTimeout(function () { w.print(); }, 250);
            });
        });
    })();
    </script>
    <?php
}
