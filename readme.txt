=== Amilia Digital Guide ===
Contributors: bluevalleyrec
Tags: amilia, smartrec, activities, program guide, recreation
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.1.0
License: GPL-2.0-or-later

Renders a seasonal digital program guide from Amilia SmartRec activity data via the [amilia_digital_guide] shortcode.

== Description ==

Replaces the manually assembled seasonal program guide PDF with a live guide
generated from the Amilia SmartRec V3 API. Activities are grouped the same way
Amilia organizes them — Program → Category → Subcategory — and rendered as
guide sections with class descriptions, ages, prerequisites, notes, and a
session table (days/times, dates, location, price, openings, Register link).

Because the guide is live:

* Cancelled or hidden activities never appear (only Status = Normal is shown)
* Prices, dates, and spots remaining are always current
* Every session links straight to registration in the Amilia store

= Shortcodes =

* `[amilia_digital_guide]` — the full configured guide with a table of contents
* `[amilia_digital_guide program="Gymnastics"]` — a single section; accepts a
  display label, an Amilia program name (with or without the " | Season"
  suffix), or a numeric Amilia program ID

= Guide Builder =

Settings → Digital Guide lists every program in the Amilia org. Check the ones
that belong in this season's guide, give them an order, an optional display
label (defaults to the Amilia name minus the season suffix), and an optional
hand-written section intro. Guide-level title, intro, and a resident-discount
note are also editable there.

Prices shown are the Amilia activity price; the Blue Valley School District
resident discount (25%) is applied at registration and referenced via the
configurable discount note.

= PDF download =

The "Download / Print PDF" button opens the guide in a bare window (no theme
header/nav) with print styles — each section starts on a new page, class
blocks don't split across pages, and the Register column is omitted — and
triggers the browser's print dialog, where visitors can save as PDF.

== Installation ==

1. Define the API credentials in `wp-config.php`:

   `define( 'AMILIA_API_KEY',    'your-api-key-here' );`
   `define( 'AMILIA_API_SECRET', 'your-api-secret-here' );`

2. Upload and activate the plugin.
3. Build the guide under Settings → Digital Guide.
4. Add `[amilia_digital_guide]` to a page.

== Caching ==

Activity data is cached in the `adg_activities_cache` transient (default
3600 s) and refreshed every 5 minutes by WP-Cron. Every successful fetch also
writes a last-known-good backup (`adg_activities_backup`, autoload=no) that is
served if the API is down, with a 2-minute cooldown that stops page loads from
re-attempting a failing API. Cached activities are trimmed to only the fields
the guide renders (~10× smaller than the raw payload). The program list is
cached for 24 hours (`adg_programs_cache`).

== Changelog ==

= 1.1.0 =
* Cover image setting (media-library picker) between the guide title and the
  intro; rendered full-width on screen and as its own cover page in the
  printed PDF. Print now waits for images to load (capped at 4 s) before
  opening the print dialog.

= 1.0.0 =
* Initial release: Guide Builder settings screen, Program → Category →
  Subcategory guide rendering, session tables with live openings and Register
  links, single-program shortcode mode, print-window PDF export, transient
  cache + last-known-good backup + WP-Cron refresh.
