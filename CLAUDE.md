# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Plugin Does

WordPress plugin for Blue Valley Recreation. Generates the seasonal **digital
program guide** (previously a manually assembled 200+ page PDF) live from the
Amilia SmartRec V3 API. Deployed to `bluevalleyrec.org`.

`[amilia_digital_guide]` renders the full configured guide with a table of
contents; `[amilia_digital_guide program="Gymnastics"]` renders one section
(accepts display label, Amilia program name with/without the `" | Season"`
suffix, or numeric program ID — resolvable even if not selected in the Guide
Builder).

## How the Guide Maps to Amilia

Amilia's hierarchy IS the guide structure:

| Guide element | Amilia field |
|---|---|
| Section ("Gymnastics") | `ProgramName` / `ProgramId` (admin-selected per season) |
| Sub-heading | `CategoryName` (first-seen order within program) |
| Class blurb | `SubCategoryName` + first non-empty `Description`/`Prerequisite`/`Note`/`AgeSummary` among its activities |
| Session table row | Individual activity: `ScheduleSummary`, `StartDate`–`EndDate`, locations, `Price`, `SpotsRemaining`, `Url` |

Only `Status === 'Normal'` activities render — Amilia marks cancelled classes
`Hidden`/`Cancelled`, so they drop out automatically.

**Resident pricing**: the API exposes a single `Price`; the printed guide's
dual `$126/$168NR` pricing is NOT available via the API. The plugin shows the
API price plus a configurable "BVSD residents receive a 25% discount" note
(`discount_note` in the guide config). Revisit if Amilia ever exposes segment
pricing.

## Credentials (wp-config.php — never stored in the plugin)

Shared with the sibling Amilia plugins:

```php
define( 'AMILIA_API_KEY',    'your-api-key-here' );
define( 'AMILIA_API_SECRET', 'your-api-secret-here' );
```

Sent as `X-AMILIA-APIKEY` / `X-AMILIA-APISECRET` request headers.

## File Load Order

`amilia-digital-guide.php` requires: `api.php` → `settings.php` → `render.php`
→ `shortcode.php`. `render.php` calls `adg_get_guide_config()` /
`adg_get_selected_programs()` / `adg_default_program_label()` from
`settings.php` at render time (not load time), so order is not fragile — but
keep it anyway.

## Architecture: Data Flow

```
Amilia API activities (Paging.Next cursor loop, ≤ ADG_MAX_PAGES)
    → adg_fetch_page() / adg_fetch_all_activities()   [api.php]
    → adg_trim_item()  — cache only guide-rendered fields (~10× smaller)
    → transient: adg_activities_cache  +  option: adg_activities_backup (autoload=no)
    ↑
WP-Cron every 5 min → adg_cron_refresh_callback()  — preserves stale cache on failure

Amilia API programs → adg_get_programs()  — 24 h transient, feeds Guide Builder checkboxes

Shortcode → adg_shortcode_handler()  [shortcode.php]
    → adg_get_all_items()            [api.php]
    → adg_render_guide()             [render.php] — Status=Normal filter,
      group Program → Category → SubCategory, inline CSS/JS
```

## Key Design Decisions

- **Trimmed cache** — `adg_trim_item()` keeps only rendered fields and
  flattens `Schedules[*].Locations[*].Name` into a unique `Locations` array
  (`LocationLabel` is empty in practice). Full payloads would put many MB in
  the options table.
- **Class blurb = first non-empty field across the subcategory's activities**
  (`adg_first_field()`); session rows sort by `StartDate`, then name.
- **Description sanitization** — `adg_sanitize_rich_text()` allows structural
  tags only and drops ALL attributes except `href`/`target`/`rel` on links:
  Amilia descriptions carry ad-hoc inline colors/fonts that fight the guide's
  typography.
- **Inline CSS/JS** — emitted once per page by `adg_output_assets()` (static
  flag); all classes `adg-`-prefixed. Sibling-plugin convention, no enqueued
  assets.
- **Instance-scoped IDs** — static render counter (`adg-1`, `adg-2`, …) so
  multiple shortcodes coexist on one page.
- **PDF = print window** — the button clones the guide into a bare
  `window.open()` document (theme header/nav never appear) with the same CSS
  plus print rules: sections `page-break-before`, `.adg-class`
  `page-break-inside: avoid`, `.adg-no-print` hides TOC/buttons/Register
  column. No server-side PDF library by design.
- **Season rollover is config, not code** — each season the admin re-checks
  the new `"… | Fall 2026"` programs in the Guide Builder. Unchecked programs
  keep their saved label/intro (sanitizer persists touched rows).

## WordPress Options

| Option | Default | Purpose |
|---|---|---|
| `adg_base_url` | Amilia activities endpoint with `{PAGE}` | Activities URL template |
| `adg_programs_url` | Amilia programs endpoint with `{PAGE}` | Programs URL template |
| `adg_cache_expiry` | 3600 | Transient TTL in seconds (0 = caching disabled; cron must never `set_transient(..., 0)`) |
| `adg_guide_config` | `[]` | Guide Builder config: `title`, `intro`, `discount_note`, `programs[id] = {include, order, label, intro}` |
| `adg_activities_backup` | `[]` | Last-known-good dataset (autoload=no) — outage fallback |
| `adg_programs_backup` | `[]` | Last-known-good program list (autoload=no) |
| `adg_last_refresh` / `adg_last_refresh_error` | — | Cron status shown on the settings page |

## Caching & Refresh

Same hardened pattern as amilia-activities 2.4: transient cache → live fetch →
`adg_activities_backup` fallback, with a 2-minute `adg_fetch_cooldown`
transient so an API outage doesn't make every page load wait through timeouts.
Admin buttons on Settings → Digital Guide: Refresh Now, Clear Cache Now,
Refresh Program List (all via `admin-post.php` + nonce, `manage_options`).

## Versioning Convention

Bump both the `Version:` header in `amilia-digital-guide.php` and
`ADG_VERSION` together. Add a changelog entry to `readme.txt`. Commit and push
— no build step.

## Deployment (folder-name convention: keep `-main`)

Identical to the sibling plugins: the installed folder on LocalWP dev,
staging, and production is `amilia-digital-guide-main` — the name GitHub's
"Download ZIP" produces from the `main` branch. Do NOT migrate to a clean
folder name and do NOT rename the default branch (WordPress identifies a
plugin by folder + file path; mixing names creates a duplicate plugin entry
and loses activation state).

- **Production updates**: download the repo ZIP from GitHub → Plugins → Add
  Plugin → Upload Plugin → "Replace current with uploaded".
- **Dev deploys**: copy changed files into the plugin folder under
  `C:\Users\ckettner\Local Sites\bvrcdev\app\public\wp-content\plugins\`
  (list the dir first — the folder name can vary; never blind-copy).
- Lint first with LocalWP's PHP:
  `$env:LOCALAPPDATA\Programs\Local\resources\extraResources\lightning-services\php-8.2.29+0\bin\win64\php.exe -l <file>`
- Siblings sharing this convention: `amilia-activities`,
  `amilia-drop-in-pool-calendar`, `amilia-fitness-calendar`,
  `amilia-open-court-calendar`, `amilia-open-gym`.
