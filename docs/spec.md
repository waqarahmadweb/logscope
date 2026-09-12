# Logscope: Technical Specification

> What the plugin is, how it is built, and the constraints it must never break. This is the reference for the shipped 1.0 design.
>
> **Related docs:**
>
> -   [../AGENTS.md](../AGENTS.md): working rules for agents and contributors (naming, security, commit style, doc-sync rule, resolved design decisions).
> -   [../ROADMAP.md](../ROADMAP.md): phased, versioned plan with checkable steps.
> -   [../CHANGELOG.md](../CHANGELOG.md): what actually shipped, per version.
>
> Anything marked **[HARD]** is non-negotiable (security, licensing, wp.org policy, architectural foundation). Everything else is a sensible default; revise only with justification and a changelog entry.

---

## 1. Project metadata

| Field                     | Value                                                                  |
| ------------------------- | ---------------------------------------------------------------------- |
| Display name              | **Logscope: Debug Log Viewer for WordPress**                           |
| Plugin slug               | `logscope`                                                             |
| Text domain               | `logscope`                                                             |
| PHP namespace             | `Logscope\`                                                            |
| Option / transient prefix | `logscope_`                                                            |
| Hook prefix               | `logscope/` (e.g. `logscope/before_alert`, `logscope/webhook_payload`) |
| REST namespace            | `logscope/v1`                                                          |
| Custom capability         | `logscope_manage` (default-maps to `manage_options`)                   |
| Min PHP                   | **8.0**                                                                |
| Min WP                    | **6.2** (tested up to 7.0)                                             |
| License                   | **GPL v2+** (wp.org requirement) **[HARD]**                            |
| Distribution              | Free forever. No paid tier, no upsells, no telemetry. **[HARD]**       |

Tagline: _Stream, filter, and group your WordPress debug log without leaving wp-admin._

---

## 2. Tech stack

-   **PHP 8.0** minimum. Typed properties, constructor promotion, `match`, nullsafe, named args. No 8.1+ features (enums, readonly, never).
-   **Composer** with PSR-4 autoloading: `Logscope\` maps to `src/`. **[HARD]**
-   **React** via `@wordpress/scripts` (standard WP toolchain, no custom webpack).
-   **@wordpress/components** for UI primitives. WP-native look is a feature.
-   **@wordpress/data** for the single app store.
-   **WP REST API** for all PHP to React traffic. **[HARD]** No `admin-ajax.php`, no `<form>` POSTs.
-   **WordPress Coding Standards** (PHPCS) via `phpcs.xml.dist`; **@wordpress/eslint-plugin** + **@wordpress/prettier-config** for JS.
-   **PHPUnit 9** with hand-rolled WP stubs (`tests/php/Stubs/`). Tests run without a WordPress install or database.

---

## 3. Folder structure

```
logscope/
├── logscope.php                  # Header, constants, autoload guard, Plugin::boot()
├── uninstall.php                 # Removes all logscope_* options, transients, user meta, cron
├── readme.txt                    # wp.org listing (two latest changelog entries only)
├── changelog.txt                 # Full wp.org-format version history
├── AGENTS.md / CLAUDE.md         # Agent + contributor rules (CLAUDE.md imports AGENTS.md)
├── README.md / ROADMAP.md / CHANGELOG.md / LICENSE
├── composer.json, package.json, phpcs.xml.dist, phpunit.xml.dist, eslint.config.mjs
│
├── src/                          # PHP, PSR-4: Logscope\
│   ├── Plugin.php                # Container + service wiring + hook registration
│   ├── Activator.php             # Cap grant, option defaults, cron scheduling
│   ├── Deactivator.php           # Cron unscheduling
│   ├── Admin/                    # Menu, AssetLoader, PageRenderer, AdminBar, DashboardWidget, SiteHealthTest
│   ├── Log/                      # LogSourceInterface, FileLogSource, LogParser, StackTraceParser,
│   │                             # LogGrouper, LogQuery, LogRepository, LogStats, LogRotator,
│   │                             # MuteStore, SourceClassifier, Severity, Entry, Frame, Group, PagedResult
│   ├── REST/                     # RestController (base) + Logs, Settings, Alerts, Mute, Presets,
│   │                             # Stats, Diagnostics controllers
│   ├── Settings/                 # Settings, SettingsSchema (single source of truth), PresetStore
│   ├── Alerts/                   # AlertDispatcherInterface, AlertCoordinator, EmailAlerter,
│   │                             # WebhookAlerter, AlertDeduplicator
│   ├── Cron/                     # CronScheduler, LogScanner
│   └── Support/                  # Capabilities, PathGuard [HARD], DiagnosticsService, path exceptions
│
├── assets/
│   ├── src/                      # React source
│   │   ├── index.js              # Mounts App on the Tools → Logscope page
│   │   ├── components/           # App, LogViewer, FilterBar, EntryRow, StackTracePanel, GroupedView,
│   │   │                         # StatsTab, SettingsPanel, MonitoringPanel, DisplayPanel,
│   │   │                         # MutedSignaturesPanel, OnboardingBanner, HelpModal, EmptyState,
│   │   │                         # Skeleton, ToastHost
│   │   ├── hooks/                # useTailPolling, useUrlQuerySync, useKeyboardShortcuts, useDebouncedValue
│   │   ├── store/                # @wordpress/data store (logs, filters, settings, UI state)
│   │   ├── api/                  # REST client wrapper over @wordpress/api-fetch
│   │   ├── utils/                # severity, csv, entryKey, filterParams, frameSource, ...
│   │   └── style.scss            # Design tokens (--logscope-*) + component styles
│   └── build/                    # @wordpress/scripts output (gitignored)
│
├── languages/logscope.pot        # Regenerated with `pnpm i18n`
├── tests/php/{Unit,Integration,Stubs}/
├── tests/js/                     # Empty placeholder
├── bin/                          # build-zip.ps1, make-pot.mjs
├── docs/spec.md                  # This file
├── .wordpress-org/               # Banner, icon, screenshots (export-ignored from the zip)
└── .github/workflows/            # ci.yml (lint, build, audit, Plugin Check), release.yml (zip on tag)
```

---

## 4. Architecture principles

1. **Thin main file.** `logscope.php` holds the header, constants, the autoload include (with a graceful admin notice when `vendor/` is missing), and one `Plugin::boot()` call.
2. **Dependency injection over globals.** A hand-rolled container in `Plugin.php` wires services. No `global $wpdb` chains in business logic.
3. **Interface boundaries for replaceable parts.** `LogSourceInterface` and `AlertDispatcherInterface` let readers and alerters be swapped via filters.
4. **REST-first.** All React to PHP traffic flows through `/wp-json/logscope/v1/*`. No AJAX actions, no admin-post handlers.
5. **File-based logs stay file-based.** Log entries are never copied into the database. Only settings, mute list, presets, cron cursors, and alert-dedup state live in `wp_options` / transients / user meta.
6. **Bounded reads.** Every read is capped: 50 MB per query (`MAX_BYTES_PER_QUERY`), tail reads from a byte cursor clamped to the last complete line, regex patterns ≤ 200 chars. A huge log must never OOM a request or a cron tick.
7. **Extensibility via hooks.** Meaningful operations fire a filter or action under `logscope/`.

---

## 5. Data model

**Entry** (parsed log line): `timestamp`, `severity`, `message`, `file`, `line`, `source` (plugin / theme / mu-plugin / core / unknown, plus slug), `frames[]` (stack trace), `raw`.

**Severity** (`Log\Severity` constants, not an enum): `fatal`, `parse`, `warning`, `notice`, `deprecated`, `strict`, `unknown`.

**Group** (Unique errors view): `signature` (md5 of severity + file + line + message with variable parts normalised), `count`, `first_seen`, `last_seen`, representative `entry`.

**Settings** (`SettingsSchema`, each stored as `logscope_<key>`):

| Key                          | Type   | Default | Notes                                                                                             |
| ---------------------------- | ------ | ------- | ------------------------------------------------------------------------------------------------- |
| `log_path`                   | string | `''`    | Empty = `WP_DEBUG_LOG` / `wp-content/debug.log`. Validated by `PathGuard`. `manage_options` only. |
| `tail_interval`              | int    | 3       | Live mode poll seconds, min 1.                                                                    |
| `alert_email_enabled`        | bool   | false   |                                                                                                   |
| `alert_email_to`             | string | `''`    | `manage_options` only.                                                                            |
| `alert_webhook_enabled`      | bool   | false   |                                                                                                   |
| `alert_webhook_url`          | string | `''`    | http(s) only. `manage_options` only.                                                              |
| `alert_dedup_window`         | int    | 1800    | Seconds per signature per dispatcher.                                                             |
| `cron_scan_enabled`          | bool   | false   | Drives the `logscope_scan_fatals` event.                                                          |
| `cron_scan_interval_minutes` | int    | 5       | 1 to 1440.                                                                                        |
| `retention_enabled`          | bool   | false   | Drives the daily `logscope_rotate_logs` event.                                                    |
| `retention_max_size_mb`      | int    | 50      | Archive once the log crosses this size.                                                           |
| `retention_max_archives`     | int    | 5       | Prune oldest archives beyond this count.                                                          |
| `default_per_page`           | int    | 50      | Infinite-scroll batch size.                                                                       |
| `default_severity_filter`    | string | `''`    | Comma-separated severities, intersected with `Severity::all()`.                                   |
| `admin_bar_enabled`          | bool   | true    |                                                                                                   |
| `timestamp_tz`               | string | `site`  | Display timezone (`site` or `utc`).                                                               |

Other persisted state: `logscope_muted_signatures` (option), `logscope_filter_presets` (user meta), `logscope_last_scanned_*` (cron cursor), `logscope_db_version`, and short-lived transients for alert dedup, stats, admin-bar counts, and dashboard widget cache.

---

## 6. REST surface

All routes under `/wp-json/logscope/v1`, all gated by `logscope_manage` through `RestController`. Errors use `logscope_rest_<reason>` codes.

| Route                    | Methods   | Purpose                                                                     |
| ------------------------ | --------- | --------------------------------------------------------------------------- | ------------------------------------------------------------ |
| `/logs`                  | GET       | Paged, filtered entries or groups (`view=list                               | grouped`, severity, regex, date range, source, tail cursor). |
| `/logs`                  | DELETE    | Clear log (soft-delete rename). Needs `?confirm=true` and `manage_options`. |
| `/logs/download`         | GET       | Stream the raw log.                                                         |
| `/logs/mute`             | GET, POST | List / add muted signatures.                                                |
| `/logs/mute/{signature}` | DELETE    | Unmute.                                                                     |
| `/settings`              | GET, POST | Read / write settings. Sensitive keys need `manage_options`.                |
| `/settings/test-path`    | POST      | Validate a custom log path through `PathGuard` without saving.              |
| `/alerts/test`           | POST      | Send a test alert through every enabled dispatcher.                         |
| `/presets`               | GET, POST | Per-user saved filter presets.                                              |
| `/presets/{name}`        | DELETE    | Remove a preset.                                                            |
| `/stats`                 | GET       | Severity breakdown, per-severity series (24h / 7d / 30d), top signatures.   |
| `/diagnostics`           | GET       | `WP_DEBUG*` constants, log path status, writability, cron status.           |

**Hooks:** filters `logscope/required_capability`, `logscope/before_alert`, `logscope/email_subject`, `logscope/webhook_payload`; actions `logscope/booted`, `logscope/alert_sent`.

**Webhook payload** (neutral JSON, reshape via `logscope/webhook_payload`): `{site, severity, message, file, line, signature, first_seen, last_seen, count}`.

---

## 7. Feature scope (shipped in 1.0)

1. **Log viewer** under Tools → Logscope: virtualized list (10k+ lines), severity pills, timestamp, `file:line`, message, stack-trace expansion with per-frame source tags.
2. **Filters**: severity multi-select, debounced server-side regex, date range, source (plugins / themes / mu-plugins / core). Filter state mirrored to the URL. Saved presets per user.
3. **Unique errors view**: groups by signature with count and first / last seen. Multi-select rows to mute or export CSV.
4. **Live mode**: toolbar toggle polling from a byte cursor; detects rotation; shows an "N new entries" pill when scrolled away.
5. **Stats dashboard**: severity breakdown bar, sparklines over 24h / 7d / 30d, top-10 signatures with click-through to a pre-filtered Logs view.
6. **Clear log** (soft-delete) and **Download log**, both capability-gated.
7. **Custom log path** with allowlisted-directory validation and a test-path button.
8. **Alerts**: email and generic webhook on new fatals, per-dispatcher dedup, "Send test alert".
9. **Scheduled scanner**: opt-in WP-Cron job (1 to 1440 min) reading only new bytes since the last tick.
10. **Retention / rotation**: opt-in daily archive above a size threshold, pruning beyond a cap.
11. **Mute**: silence known signatures; unmute from Settings.
12. **Admin surfacing**: admin-bar indicator with today's count, "Recent errors" Dashboard widget, Site Health test.
13. **Onboarding banner** when `WP_DEBUG_LOG` is off; **diagnostics** card showing the debug constants.
14. **Keyboard shortcuts** and a help modal; full keyboard and screen-reader support; light theme only.

---

## 8. Out of scope for 1.0 (planned, see ROADMAP Phase 22)

-   One-click `WP_DEBUG` toggle that edits `wp-config.php` (1.1.0; needs its own security review).
-   Slack / Discord / Teams payload formatters (1.2.0; today users reshape via the filter).
-   SSE / WebSocket live streaming (1.3.0; Live mode polls).
-   Multisite / network-wide aggregation (1.4.0).
-   Source preview, WP-CLI commands, request context, external log stores (Loki, Elastic, Datadog) (1.5.0+).
-   Dark mode (removed before 1.0; WP 7.0 exposed a half-built palette).
-   Roles beyond the single `logscope_manage` capability; import / export of historical logs.

---

## 9. Security requirements [HARD]

-   **Capability check on every REST route**: `current_user_can( 'logscope_manage' )`. Never relax.
-   **Full-admin gate** (`manage_options`) on `log_path`, `alert_webhook_url`, `alert_email_to`, and clear-log. The grantable cap must not be able to repoint the log or redirect alerts.
-   **Nonces** via WP REST auth; never disabled.
-   **Path traversal prevention** via `PathGuard`: custom paths must resolve inside the allowlist (WP root, `wp-content`, `ABSPATH`), have a `*.log` or `debug.log*` basename, reject `..` before `realpath`, reject symlinks that escape. Tested with adversarial inputs.
-   **Anti-SSRF**: webhook POSTs use `wp_safe_remote_post()`, refusing private / loopback / internal hosts.
-   **Escape on output, sanitize on input.** Anything read from disk is sanitized before it leaves a REST response.
-   **Bounded input**: regex ≤ 200 chars, mute and preset counts capped, severity filters intersected with the known set.
-   **Rate-limited alerts**: per-dispatcher dedup window via transients.
-   **Unguessable archives**: cleared and rotated logs carry a random suffix.
-   **No external HTTP** except user-configured webhooks. No telemetry, no update checks outside wp.org, no remote assets.
-   **Uninstall cleanup** removes every `logscope_*` option, transient, user meta, and cron event.

---

## 10. wp.org submission readiness

-   `readme.txt` headers: Contributors, Tags, Requires at least, Tested up to, Stable tag, Requires PHP, License, License URI. `Stable tag` matches the `Version:` header in `logscope.php`.
-   `readme.txt` keeps the two latest changelog entries; `changelog.txt` holds the full history.
-   Banner (1544×500), icon (256×256), and six screenshots in `.wordpress-org/`, captions paired by index with `== Screenshots ==`.
-   Clear Privacy section in `readme.txt` (nothing leaves the site except user-configured alerts).
-   Distribution zip built by `release.yml` on a `v*` tag: production `vendor/`, built `assets/build/`, no dev files (enforced by `.gitattributes export-ignore` and a forbidden-path check).
-   `composer lint`, `composer test`, `pnpm lint:js`, and Plugin Check all clean in CI.
