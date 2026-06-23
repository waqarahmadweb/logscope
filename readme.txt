=== Logscope ===
Contributors: waqarahmadweb
Tags: debug-log, error-log, logging, log-viewer, alerts
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.18.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

View, filter, group, and get alerts on your WordPress debug log without leaving wp-admin.

== Description ==

Logscope turns `wp-content/debug.log` into a real admin tool. Instead of SSHing into the server to `tail -f` a file, you open **Tools → Logscope** and get a virtualized log viewer that handles thousands of lines, severity and regex filters, error grouping by signature, stack-trace expansion, a tail mode that polls for new entries, alerts for new fatals over email or webhook, a scheduled scanner that drives those alerts on a cron, opt-in size-based log rotation, mute for noisy known-and-accepted signatures, saved filter presets, a stats dashboard, and an onboarding banner that walks you through enabling `WP_DEBUG_LOG` if it's off.

**Free forever.** No paid tier, no telemetry, no upsells.

= Features =

* **Log viewer** — virtualized list (10k+ lines without lag) with severity pills, timestamp, file:line, message, and stack-trace expansion for fatals.
* **Filters** — severity multi-select, debounced regex search (server-side, message-scoped), date range, source dropdown (plugins / themes / mu-plugins / core). Filter state is mirrored to the URL.
* **Grouped view** — collapses duplicate errors by signature (file:line + normalised message). Each group shows count, sample message, first/last seen.
* **Bulk actions in grouped view** — multi-select rows, then mute the selection in one batch or export to CSV.
* **Tail mode** — toolbar toggle that polls for new entries on the configured interval. Detects log rotation; "N new entries" pill when you've scrolled away.
* **Stats dashboard** — severity breakdown bar, sparkline grid per severity over 24h / 7d / 30d windows, top-10 signatures table with click-through to a pre-filtered Logs view.
* **Alerts** — email and/or generic webhook on new fatals. Per-dispatcher dedup so silencing email on a noisy fatal does not silence the webhook on the same fatal. "Send test alert" button to verify wiring.
* **Scheduled scanner** — opt-in WP-Cron job (1–1440 minute interval) that reads new bytes since the last tick, filters to fatal/parse, groups, and feeds the alert pipeline.
* **Retention / log rotation** — opt-in daily cron archives `debug.log` to `debug.log.archived-YYYYMMDD-HHMMSS` once it crosses a configurable size, then prunes oldest archives beyond a configurable cap.
* **Mute** — silence a known-and-accepted signature so it stops dominating the Logs view. Settings tab carries an unmute panel.
* **Filter presets** — per-user saved filter sets with name, recallable from a FilterBar dropdown.
* **Onboarding** — first-time admins on a host with `WP_DEBUG_LOG` off see an actionable banner with the exact `wp-config.php` lines to add.
* **Reason-aware empty state** — "No entries" never lies; the empty screen names the underlying cause (file missing, file empty, all entries muted, filters too narrow).
* **Diagnostics** — `GET /diagnostics` REST endpoint reports `WP_DEBUG`, `WP_DEBUG_LOG`, the resolved log path, file existence, size, and mtime.
* **Keyboard shortcuts** — `/` focuses search, `g` toggles list ↔ grouped, `t` toggles tail mode, `?` opens the help modal.
* **Accessibility** — WAI-ARIA tablist, roving tabindex, `role="status"` / `role="alert"` live regions, focus-visible ring, WCAG AA-contrast severity pills.
* **i18n** — every user-facing string is translatable. `languages/logscope.pot` ships in the zip.

= Architecture =

REST-first (`/wp-json/logscope/v1/*`), React admin UI on `@wordpress/data` and `@wordpress/components`. All log access flows through a `PathGuard` allowlist that resolves and validates paths before any filesystem call. Every REST route is gated by a `logscope_manage` capability check on a shared `RestController` base.

= Open source / build =

Logscope is fully open source (GPLv2+) and developed in the open at https://github.com/waqarahmadweb/logscope. The admin UI is built with `@wordpress/scripts`: the un-minified source lives in `assets/src/`, and the bundled `assets/build/` files are compiled from it (`pnpm install && pnpm build`). The repository is the canonical source for the human-readable code behind the shipped, minified assets.

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, or unzip it into `wp-content/plugins/`.
2. Activate **Logscope** through the **Plugins** menu in WordPress.
3. Make sure `WP_DEBUG_LOG` is enabled in your `wp-config.php`. If it isn't, Logscope will show an onboarding banner with the exact lines to add:

    `define( 'WP_DEBUG', true );`
    `define( 'WP_DEBUG_LOG', true );`
    `define( 'WP_DEBUG_DISPLAY', false );`

4. Open **Tools → Logscope** and you're in.

== Frequently Asked Questions ==

= Does Logscope write to my debug log? =

No. Logscope is a read-only viewer. The only write operation it performs is the opt-in size-based log rotation (off by default) which renames `debug.log` to a timestamped archive once the file exceeds a configured size, and the soft-delete "Clear log" action which renames the file rather than deleting it so a wiped log is still recoverable.

= Will Logscope work without `WP_DEBUG_LOG`? =

No — Logscope reads the file WordPress writes when `WP_DEBUG_LOG` is on. If the constant is off (or `WP_DEBUG` itself is off), the plugin shows an onboarding banner with the exact `wp-config.php` lines to add.

= Will Logscope slow my site down? =

No. Reads are tail-bounded to a 50 MiB budget so a pathological multi-gigabyte log cannot exhaust PHP's `memory_limit`. The scheduled scanner is opt-in and reads only the slice appended since the last tick. The viewer paginates server-side and virtualizes the rendered DOM.

= Where do alerts go? =

Wherever you configure them. Both backends — email and generic webhook — are off by default. When you enable email, alerts go to the address you specify in **Settings → Alerts**. When you enable webhook, alerts POST a sanitised JSON payload (severity, signature, sample message, count, first/last seen) to the URL you specify. No data is sent anywhere unless you explicitly enable a backend.

= Does Logscope support multisite? =

Logscope is built and tested for single-site WordPress. Multisite is not officially supported in v1.0.

== Screenshots ==

1. Log viewer with severity filters and regex search.
2. Grouped view with bulk actions (mute / export CSV).
3. Stats dashboard — severity breakdown, sparkline grid, top signatures.
4. Alerts settings — email and webhook configuration with test send.
5. Mute panel for unmuting silenced signatures.
6. Onboarding banner shown when `WP_DEBUG_LOG` is missing.

== Privacy ==

Logscope reads `wp-content/debug.log` (or the path you configure in **Settings**). It does **not** send data anywhere by default. No telemetry. No third-party services. No remote calls.

The only outbound traffic Logscope can produce is from the alerts subsystem, and only when you explicitly enable it:

* **Email alerts** — when enabled, Logscope sends a plain-text email through `wp_mail()` to the recipient address you configure. The email body contains a sanitised summary of the error (severity, signature, sample message, count, first/last seen).
* **Webhook alerts** — when enabled, Logscope POSTs a JSON payload to the URL you configure. The payload contains the same sanitised summary as the email body. Webhook URLs are restricted to `http://` and `https://` schemes; redirects are not followed.

Diagnostics data exposed through the `GET /diagnostics` REST endpoint (gated by the `logscope_manage` capability) covers `WP_DEBUG`, `WP_DEBUG_LOG`, the resolved log path, file size, and modification time. This information is already visible to anyone with the manage capability through the existing settings surface.

== Changelog ==

= 0.18.0 =
Phase 20: pre-1.0 security gate and UI fixes.
* Ships **light-only** for now — WordPress 7.0 began honouring OS dark mode inside wp-admin, which exposed a broken half-dark palette (light background, dark surfaces). A correctly built dark mode returns in a later release.
* Grouped view fixes: the expanded detail panel now spans the full row width, the phantom "×undefined" group is gone, and the bulk-action bar no longer overlaps the first row.
* Settings fix: clicking "Send test alert" with an unsaved "Watch the log and send alerts" toggle no longer flips the toggle back off.
* Security hardening: webhook alerts now use WordPress's safe HTTP transport, which refuses requests to private / internal / loopback addresses (anti-SSRF); plus tighter input validation on the mute and preset REST routes and direct-access guards across every PHP file.

= 0.17.0 =
Phase 19: pre-1.0 feature parity.
* New admin-bar status indicator on every wp-admin (and front-end-when-logged-in) screen for users with the `logscope_manage` cap. Green dot when `WP_DEBUG_LOG` is on, grey when off; a red badge shows today's entry count when non-zero. Click the node to jump to **Tools → Logscope**. Toggle the indicator off from the Display section of Settings if you find it noisy.
* New "Logscope · Recent errors" widget on the wp-admin Dashboard. The five most recent log entries with severity pills, monospace messages, and human-readable relative times — `5 mins ago` rather than raw timestamps. "View all" footer link returns you to the Logs tab.
* New Tools → Site Health test that goes red when fatal or parse errors occurred in the last 24 hours, amber when only warnings did, green when the window is clean. Action links deep-link into the Logs tab with the matching severity + 24-hour window pre-selected.
* Stack-trace panel restyled with a four-column grid (frame index · source tag · file:line · call) and color-coded source tags so plugin and theme frames stand out from core glue at a glance.

For older releases, see the bundled changelog.txt or CHANGELOG.md on GitHub:
https://github.com/waqarahmadweb/logscope/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 0.18.0 =
Security hardening (anti-SSRF webhook transport, tighter REST validation) plus grouped-view and settings UI fixes. Ships light-only while a proper dark mode is rebuilt.

= 0.17.0 =
Adds an admin-bar status indicator, a Dashboard widget, and a Site Health test. New `admin_bar_enabled` setting (default on) lets you hide the bar item if you find it noisy.

