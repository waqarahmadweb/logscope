# Logscope — Technical Specification

> The long-form technical specification: architectural intent, feature scope, security requirements, wp.org submission constraints.
>
> **Related docs:**
> - [../AGENTS.md](../AGENTS.md) — active working rules for AI agents and contributors (naming, security, commit style, doc-sync rule). Absorbs the "Ruleset for the Agent" that used to live in this file.
> - [../ROADMAP.md](../ROADMAP.md) — phased, versioned execution plan with checkable steps.
> - [../CHANGELOG.md](../CHANGELOG.md) — what's actually shipped.
>
> Anything marked **[HARD]** is non-negotiable (security, licensing, wp.org policy, architectural foundation). Everything else is a sensible default — revise only with justification.

---

## 1. Project Metadata

| Field | Value |
|---|---|
| Display name | **Logscope — Debug Log Viewer for WordPress** |
| Plugin slug | `logscope` |
| Text domain | `logscope` |
| PHP namespace | `Logscope\` |
| Option / transient prefix | `logscope_` |
| Hook prefix | `logscope/` (e.g. `logscope/log_parsed`, `logscope/before_alert`) |
| Custom capability | `logscope_manage` (default-map to `manage_options`) |
| Min PHP | **8.0** |
| Min WP | **6.2** |
| License | **GPL v2+** (wp.org requirement) **[HARD]** |
| Distribution | Free forever. No paid tier, no upsells, no telemetry. **[HARD]** |

Tagline: *Stream, filter, and group your WordPress debug log without leaving wp-admin.*

---

## 2. Tech Stack

- **PHP 8.0** minimum. Use typed properties, arrow functions, `null` coalescing, constructor promotion, `match`. No features beyond 8.0.
- **Composer** with PSR-4 autoloading — namespace `Logscope\` mapped to `src/`. **[HARD]**
- **React** via `@wordpress/scripts` build toolchain (standard WP workflow, no custom webpack).
- **@wordpress/components** for all UI primitives (Button, Panel, SelectControl, etc.) — WP-native look is a feature.
- **@wordpress/data** for store / state management.
- **WP REST API** for all PHP ↔ React communication. **[HARD]** No admin-ajax.php, no `<form>` POSTs.
- **WordPress Coding Standards** (PHPCS) for PHP — enforce via `phpcs.xml.dist`.
- **@wordpress/eslint-plugin** + **@wordpress/prettier-config** for JS.

---

## 3. Folder Structure

```
logscope/
├── logscope.php                      # Main plugin file: header + bootstrap only
├── uninstall.php                     # Cleans options on full uninstall
├── readme.txt                        # wp.org-format readme
├── composer.json
├── package.json
├── phpcs.xml.dist
├── .editorconfig
├── .gitignore                        # Excludes vendor/, node_modules/, build/
├── LICENSE                           # GPL v2
├── CHANGELOG.md
├── CLAUDE.md                         # Agent rules (this doc, or a trimmed version)
│
├── src/                              # PHP source — PSR-4: Logscope\
│   ├── Plugin.php                    # Main orchestrator, service wiring
│   ├── Activator.php
│   ├── Deactivator.php
│   │
│   ├── Admin/
│   │   ├── Menu.php                  # Registers Tools → Logscope page
│   │   ├── AssetLoader.php           # Enqueues React bundle on plugin page only
│   │   └── PageRenderer.php          # Renders React mount point
│   │
│   ├── Log/
│   │   ├── LogSourceInterface.php
│   │   ├── FileLogSource.php         # Reads debug.log / custom paths
│   │   ├── LogParser.php             # Severity detection, timestamp parsing
│   │   ├── StackTraceParser.php
│   │   ├── LogGrouper.php            # Signature-based error grouping
│   │   └── LogRepository.php         # Paginated, filtered access
│   │
│   ├── REST/
│   │   ├── RestController.php        # Abstract base: caps, nonces, schema
│   │   ├── LogsController.php
│   │   ├── SettingsController.php
│   │   └── AlertsController.php
│   │
│   ├── Settings/
│   │   ├── Settings.php              # Get/set with defaults
│   │   └── SettingsSchema.php        # Single source of truth for shape
│   │
│   ├── Alerts/
│   │   ├── AlertDispatcherInterface.php
│   │   ├── AlertCoordinator.php      # Fanout + dedupe
│   │   ├── EmailAlerter.php
│   │   ├── WebhookAlerter.php        # Generic JSON POST (Slack/Discord/Teams/n8n)
│   │   └── AlertDeduplicator.php     # Transient-based rate limiting
│   │
│   ├── Cron/
│   │   └── LogScanner.php            # Scheduled scan for new fatals → alerts
│   │
│   └── Support/
│       ├── Capabilities.php
│       ├── PathGuard.php             # Path traversal prevention [HARD]
│       └── Sanitizer.php
│
├── assets/
│   ├── src/                          # React source
│   │   ├── index.js                  # App mount (wp-admin page)
│   │   ├── App.jsx
│   │   ├── components/
│   │   │   ├── LogViewer/            # Virtualized list + tail mode
│   │   │   ├── FilterBar/            # Severity, regex, date range
│   │   │   ├── EntryRow/
│   │   │   ├── StackTracePanel/
│   │   │   ├── GroupedView/          # Errors grouped by signature
│   │   │   ├── SettingsPanel/
│   │   │   └── EmptyState/
│   │   ├── hooks/                    # useLogs, useTail, useSettings
│   │   ├── api/                      # REST client wrapper
│   │   ├── store/                    # @wordpress/data registrations
│   │   └── utils/
│   └── build/                        # @wordpress/scripts output (gitignored)
│
├── languages/
│   └── logscope.pot                  # Generated on release build
│
├── tests/
│   ├── php/
│   │   ├── bootstrap.php
│   │   ├── Unit/                     # Parser, grouper, path guard, dedup
│   │   └── Integration/              # REST endpoints
│   └── js/                           # Jest, kept minimal for MVP
│
├── .wordpress-org/                   # wp.org listing assets
│   ├── banner-1544x500.png
│   ├── icon-256x256.png
│   └── screenshot-1.png … screenshot-5.png
│
└── .github/
    └── workflows/
        ├── lint.yml                  # PHPCS + ESLint
        └── release.yml               # Build zip, strip dev deps
```

---

## 4. Architecture Principles

1. **Thin main file.** `logscope.php` contains only the plugin header, autoload include, and a single `Plugin::boot()` call. All logic lives under `src/`.
2. **Dependency injection over globals.** A lightweight container (hand-rolled in `Plugin.php`, no package needed) wires services. No `global $wpdb` chains inside business logic.
3. **Interface boundaries for replaceable parts.** `LogSourceInterface` and `AlertDispatcherInterface` exist so readers/alerters can be swapped or extended via filters.
4. **REST-first.** All React ↔ PHP traffic flows through `wp-json/logscope/v1/*`. No AJAX actions, no hidden admin-post handlers.
5. **File-based logs stay file-based.** Do not copy log entries into the database. Read, parse, and serve on demand. Settings and alert state are the only things in `wp_options` / transients.
6. **Extensibility via hooks.** Every meaningful operation fires a filter or action under the `logscope/` prefix so power users can extend without forking.

---

## 5. MVP Feature Scope (v1.0)

1. **Log viewer page** under Tools → Logscope (virtualized list, handles 10k+ lines).
2. **Tail mode** — live-updating view (polling every 2–5s is fine for v1; SSE is a v1.1 nice-to-have).
3. **Filters** — severity (Fatal / Warning / Notice / Deprecated), regex search, date range, source plugin/theme (parsed from path).
4. **Error grouping** — collapse duplicate errors by signature (file:line + message shape). Show first-seen, last-seen, count.
5. **Stack trace parsing** — expandable, file paths linked to clipboard copy.
6. **Clear log** / **Download log** (with confirmation + capability check).
7. **Custom log paths** — settings field with allowlisted directory validation.
8. **Email alerts** on new fatal errors (configurable recipients, rate-limited).
9. **Webhook alerts** — generic JSON POST; docs include ready-made templates for Slack/Discord/Teams/n8n.
10. **Settings page** (React, same admin page, tabbed) — all options configurable via UI.

---

## 6. Out of Scope for v1

- Multisite / network-wide log aggregation *(v1.1)*
- Log retention policies / rotation management
- External log storage (Loki, Elastic, Datadog)
- Advanced analytics dashboards
- Role/team management beyond the single `logscope_manage` capability
- SSE / WebSocket live streaming *(v1.1)*
- Import/export of historical logs

---

## 7. Security Requirements **[HARD]**

- **Capability check** on every REST route: `current_user_can( 'logscope_manage' )`. Never relax this.
- **Nonces** via WP REST auth — do not disable.
- **Path traversal prevention** — `PathGuard` class. Custom log paths MUST resolve to an allowlisted set of directories (WP root, wp-content, ABSPATH). Reject symlinks that escape. Reject `..` explicitly before realpath. Test this with adversarial inputs.
- **Escape on output, sanitize on input.** No exceptions. React handles most escaping; PHP-side REST responses still need proper sanitization for anything read from disk.
- **Rate-limit alerts** — webhook/email dedup window (default 5 min) via transients. Prevents alert storms from flooding Slack channels.
- **No external HTTP** except user-configured alert webhooks. No telemetry, no update checks outside wp.org, no remote fonts/CSS.
- **Uninstall cleanup** — `uninstall.php` removes all `logscope_*` options and transients.

---

## 8. Ruleset for the Agent

### Must do
- Use Composer PSR-4 autoloading. No `require_once` chains. **[HARD]**
- Prefix every hook with `logscope/`. **[HARD]**
- Prefix every option, transient, cron event, and user meta key with `logscope_`. **[HARD]**
- Wrap all user-facing strings in `__()`, `_e()`, `esc_html__()`, etc. with text domain `logscope`. **[HARD]**
- Register the REST namespace as `logscope/v1`. **[HARD]**
- Load the React bundle only on the Logscope admin page (check screen ID in `AssetLoader`).
- Ship a `readme.txt` in wp.org format from day 1 (Stable Tag, Tested Up To, Requires at least, Requires PHP).
- Generate the `.pot` file as part of the release build.
- Write PHPUnit tests for at minimum: `LogParser`, `StackTraceParser`, `LogGrouper`, `PathGuard`, `AlertDeduplicator`.

### Must not do
- **No paid tier, no license gating, no "Pro" code paths, no upsell UI.** Always free. **[HARD]**
- No telemetry, no phone-home, no analytics. **[HARD]**
- No jQuery. No PHP-rendered admin forms. All UI is React. **[HARD]**
- No bundled minified dependencies tracked in VCS (wp.org will reject it).
- No reading/writing files outside the allowlisted log directories. **[HARD]**
- No database table for log entries. Logs are read from disk on demand.
- No multisite-specific code in v1 — single-site assumption throughout.
- No external CDN loads for fonts, icons, or CSS.

### Preferred patterns
- Strict types in PHP files where practical (`declare(strict_types=1);`).
- Return early; avoid deep nesting.
- Pure functions for parsers — makes them trivially testable.
- Keep React components small; colocate their styles as SCSS modules or emotion-style inline (follow `@wordpress/scripts` convention).
- Use `@wordpress/data` stores for anything touched by more than one component.

---

## 9. wp.org Submission Readiness

- `readme.txt` with correct headers (Contributors, Tags, Requires at least, Tested up to, Stable tag, Requires PHP, License, License URI).
- Plugin header in `logscope.php` with matching versioning.
- Banner (1544×500) and icon (256×256) in `.wordpress-org/`.
- 3–5 screenshots showing: log viewer, grouped view, filters in action, settings, alert config.
- No trademarked terms in the slug or display name.
- Clear "Privacy" section in readme (should be easy — no data leaves the site except user-configured webhooks).

---

## 10. Suggested Build Order

Not mandatory, but this sequence de-risks the hardest parts first:

1. Skeleton plugin + Composer autoload + `@wordpress/scripts` build pipeline running.
2. `FileLogSource` + `LogParser` + unit tests. *Prove you can read and parse real debug.log output reliably.*
3. REST API: `GET /logs` with pagination and filters.
4. React shell + `LogViewer` component consuming the REST endpoint.
5. Filters, grouping, stack trace expansion.
6. Settings page + `SettingsController`.
7. Alert coordinator + email alerter + webhook alerter + dedup.
8. Cron scanner for new fatals.
9. Polish: empty states, loading skeletons, keyboard shortcuts, accessibility pass.
10. readme.txt, screenshots, wp.org assets.
11. PHPUnit + ESLint in CI.

---

## 11. Open Questions to Surface Back

If the agent hits any of these, ask before deciding:

- Should "Clear log" soft-delete (rename with timestamp) or hard-delete? *(Suggest soft-delete for safety.)*
- Webhook payload shape — mimic Slack's incoming webhook format (`text` field) or use a neutral shape and let users adapt downstream? *(Suggest neutral + document Slack/Discord mapping.)*
- Tail polling interval — fixed 3s, or user-configurable? *(Suggest configurable, 3s default, min 1s.)*
- Regex filter — run server-side (safer, better for large logs) or client-side (snappier)? *(Suggest server-side with a length cap on the pattern.)*
