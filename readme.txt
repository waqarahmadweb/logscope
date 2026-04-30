=== Logscope ===
Contributors: waqarahmadweb
Tags: debug-log, error-log, logging, log-viewer, alerts
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.15.0
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
* **Dark mode** — follows `prefers-color-scheme` and the WordPress `admin-color-midnight` admin scheme.
* **i18n** — every user-facing string is translatable. `languages/logscope.pot` ships in the zip.

= Architecture =

REST-first (`/wp-json/logscope/v1/*`), React admin UI on `@wordpress/data` and `@wordpress/components`. All log access flows through a `PathGuard` allowlist that resolves and validates paths before any filesystem call. Every REST route is gated by a `logscope_manage` capability check on a shared `RestController` base.

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

= 0.15.0 =
Release infrastructure for the v1.0.0 wp.org cut: this `readme.txt`, the `.wordpress-org/` asset spec, and the GitHub release workflow that builds the distribution zip on tag push.

= 0.14.0 =
Phase 16: onboarding, diagnostics, bulk actions.
* Onboarding banner above the FilterBar when `WP_DEBUG_LOG` is off — embeds the exact `wp-config.php` lines and a link to the WordPress handbook. Dismissible per browser session.
* Reason-aware empty-log state — the empty screen now names the underlying cause (file missing, file empty, all entries muted, filters too narrow) instead of one generic line.
* `GET /diagnostics` REST endpoint over a new `DiagnosticsService` — typed snapshot of `WP_DEBUG` / `WP_DEBUG_LOG` / log path / size / mtime, fed to the banner and the empty state.
* Bulk actions in grouped view — per-row checkbox, tri-state Select-all header, "Mute selected" (batched POST to `/logs/mute`) and "Export selected" (CSV blob built client-side, RFC 4180 quoted, UTF-8 BOM for Excel auto-detection).

= 0.13.0 =
Phase 15: stats dashboard.
* New **Stats** tab with 24h / 7d / 30d range and hour / day bucket toggles.
* Severity breakdown bar (proportions across the range), sparkline grid per severity (hand-rolled SVG, no chart library), top-10 signatures table with "View in Logs" click-through that pre-populates the FilterBar.
* New `GET /stats` REST endpoint with transient caching keyed by file size + mtime + range + bucket + snapped-now boundary.

= 0.12.0 =
Phase 14: retention, mute, presets.
* Opt-in size-based log rotation — daily cron renames `debug.log` to `debug.log.archived-YYYYMMDD-HHMMSS` once it crosses a configurable size, then prunes oldest archives beyond a configurable cap.
* Mute — per-signature silencing with reason. Settings tab carries an unmute panel. Mute filter is on by default at the repository layer; `?include_muted=true` opts the management UI back in.
* Filter presets — per-user named filter sets, save / load / delete from a FilterBar dropdown. Captures view mode alongside filter values.

= 0.11.0 =
Phase 13: scheduled fatal scanner.
* Opt-in WP-Cron scanner reads bytes appended since the last tick, filters to fatal / parse, groups, and dispatches through the alert coordinator.
* Configurable interval (1–1440 minutes), rotation-aware cursor, "Last scan: …" status line in Settings.

= 0.10.0 =
Phase 12: alerts subsystem.
* Email and generic webhook backends fanned out by a single coordinator with per-dispatcher signature-keyed dedup.
* Webhook hardened with two-layer http(s) scheme allowlist, no redirect following (anti-SSRF), 5s timeout.
* "Send test alert" button in Settings hits a new `POST /alerts/test` route.

= 0.9.0 =
Phase 11: polish, accessibility, dark mode, i18n.
* First dedicated stylesheet (variable-driven for light / OS dark / `admin-color-midnight`).
* Loading skeletons replace the bare spinner. Toast / Snackbar surface for save success and REST failures.
* Keyboard shortcuts (`/`, `g`, `t`, `?`), WAI-ARIA tablist with roving tabindex and arrow-key nav, `role="status"` / `role="alert"` regions, focus-visible ring.
* `languages/logscope.pot` (87 strings, both PHP and JS).

= 0.8.0 =
Phase 8: settings tab is now a real form.
* Edit `log_path` and `tail_interval`. Save through `POST /settings`.
* Side-effect-free `POST /settings/test-path` probe that surfaces `PathGuard`'s verdict inline, with a clean fall-through to a parent-directory writability check for not-yet-created custom paths.

= 0.7.0 =
Phase 7: filters, grouping, stack-trace expansion, tail mode.
* FilterBar with severity multi-select, debounced regex, date range, source dropdown — URL-mirrored.
* Grouped view backed by signature grouping. List ↔ grouped mode toggle.
* `StackTracePanel` with click-to-copy `file:line` for fatals.
* Tail mode polling `GET /logs?since=<last_byte>` with rotation detection and an "N new entries" pill.

= 0.6.0 =
Phase 6: admin page and React viewer shell.
* **Tools → Logscope** menu, gated by `logscope_manage`.
* React app on `@wordpress/data` consuming the REST surface. Virtualized log list (`react-window`).
* Two-tab layout (Logs · Settings) with hashchange-driven routing.

= 0.5.0 =
Phases 4 and 5: REST surface and settings backend.
* `GET / DELETE /logs`, `GET /logs/download`, `GET / POST /settings`.
* Schema-driven settings facade. Abstract `RestController` base centralising 401 / 403.

= 0.4.0 =
Phase 3: log-reading and parsing foundation.
* `PathGuard` allowlist with traversal protection.
* `FileLogSource` (chunked reads, no whole-file loads), `LogParser` (six WP severity tokens), `StackTraceParser`, `LogGrouper` (md5 signature with hex / string / int normalisation), `LogRepository` facade.

= 0.3.0 =
Phase 2: plugin bootstrap.
* DI container, activation / deactivation / uninstall lifecycle, capability helper, PHPUnit + Brain Monkey scaffolding.

= 0.2.0 =
Phase 1: tooling.
* Composer + pnpm, phpcs (WordPress Coding Standards) + ESLint / Prettier, GitHub Actions CI, husky + lint-staged pre-commit.

= 0.1.0 =
Initial scaffold.

== Upgrade Notice ==

= 0.15.0 =
Release infrastructure only — no runtime changes from 0.14.0.
