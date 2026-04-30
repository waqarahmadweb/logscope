# Logscope Roadmap

> Stepwise plan to take Logscope from scaffold (v0.1.0) to wp.org-ready v1.0.0 — and beyond.
> **Check off steps as you finish them.** One step ≈ 1–3 hours of focused work. Some days you may finish 1 step, some days 3.
>
> Related: [AGENTS.md](AGENTS.md) (rules) · [CHANGELOG.md](CHANGELOG.md) (what's shipped)

---

## Legend

-   `[ ]` = not started · `[~]` = in progress · `[x]` = done
-   **AC** = acceptance criteria (what "done" means for this step)
-   **Deps** = steps that must be complete first
-   🔒 = security-sensitive — run `security-review` skill before committing
-   🏷️ = **version bump step** — closes a phase with a `vX.Y.Z` tag. Each bump edits `Version:` in [logscope.php](logscope.php), moves `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md) to a dated section, and tags the commit.

## Version line at a glance

| Version   | Closes               | Theme                                                         | Public? |
| --------- | -------------------- | ------------------------------------------------------------- | ------- |
| 0.1.0     | Phase 0 ✅           | Scaffold                                                      | no      |
| 0.2.0     | Phase 1              | Tooling & developer loop                                      | no      |
| 0.3.0     | Phase 2              | Plugin bootstrap + lifecycle                                  | no      |
| 0.4.0     | Phase 3              | Log reading & parsing foundation                              | no      |
| 0.5.0     | Phases 4 + 5         | REST API + settings backend                                   | no      |
| 0.6.0     | Phase 6              | Admin page + React viewer shell                               | no      |
| 0.7.0     | Phase 7              | Filters, grouping, trace, tail                                | no      |
| 0.8.0     | Phase 8              | Settings UI + custom log path                                 | no      |
| 0.9.0     | Phase 11             | Polish, a11y, i18n (.pot)                                     | no      |
| 0.10.0    | Phase 12             | Alerts (email + webhook + dedup)                              | no      |
| 0.11.0    | Phase 13             | Scheduled fatal scanner (cron)                                | no      |
| 0.12.0    | Phase 14             | Retention, mute, filter presets                               | no      |
| 0.13.0    | Phase 15             | Stats dashboard                                               | no      |
| 0.14.0    | Phase 16             | Onboarding, diagnostics, bulk actions                         | no      |
| 0.15.0    | Phase 17.1–17.4      | wp.org release infrastructure                                 | no      |
| 0.x.0     | Phase 17.5–17.6      | Pre-1.0 changes (TBD scope)                                   | no      |
| **1.0.0** | **Phase 17.7–17.11** | **🚀 wp.org submission**                                      | **YES** |
| 1.1.0+    | Post-1.0             | Live streaming, multisite, source preview, request context, … | YES     |

Pre-1.0 bumps are git tags only — nothing leaves the repo. **The wp.org release line is v1.0.0 and only v1.0.0.**

> **2026-04-30 restructure:** Phases 12–16 were added to flesh out the plugin before the wp.org cut. The original "Phase 12 = release infrastructure → cut v1.0.0" became Phase 17. Alerts and cron were pulled forward from post-1.0 (v1.1.0 / v1.2.0) into Phases 12 / 13 so the submission lands with a usable feature set rather than a viewer-only MVP.

---

## Phase 0 — Scaffold ✅

-   [x] **0.1** Plugin folder, folder tree, AI rules (AGENTS.md + CLAUDE.md), LICENSE, .gitignore, .gitattributes, plugin header at v0.1.0
    -   **Shipped in**: commit `ad9088f` · v0.1.0

---

## Phase 1 — Tooling & developer loop

Goal: Any contributor can clone the repo, run two install commands, and have linting + the React build pipeline working.

-   [x] **1.1** Add `composer.json`

    -   PSR-4: `Logscope\\` → `src/`
    -   Dev deps: `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, `phpcompatibility/phpcompatibility-wp`, `dealerdirect/phpcodesniffer-composer-installer`, `phpunit/phpunit` (^9 for PHP 8.0)
    -   Scripts: `lint` (phpcs), `lint:fix` (phpcbf), `test` (phpunit)
    -   Run `composer install`; commit `composer.lock`.
    -   **AC**: `composer lint` runs (will find nothing to lint in empty `src/` — that's fine).
    -   **Commit**: `chore: add composer tooling (phpcs + phpunit)`

-   [x] **1.2** Add `phpcs.xml.dist`

    -   Rules: `WordPress-Core`, `WordPress-Docs`, `WordPress-Extra`, `PHPCompatibilityWP`
    -   `testVersion: 8.0-`, text domain `logscope`, exclude `vendor/`, `node_modules/`, `assets/build/`
    -   **AC**: `composer lint` still passes.
    -   **Commit**: `chore: add phpcs ruleset`

-   [x] **1.3** Add `package.json` + `@wordpress/scripts`

    -   Deps: `@wordpress/scripts`, `@wordpress/components`, `@wordpress/data`, `@wordpress/i18n`, `@wordpress/api-fetch`, `react`, `react-dom`
    -   Dev deps: `@wordpress/prettier-config`, `@wordpress/eslint-plugin`, `prettier`, `eslint`
    -   Scripts: `build`, `start`, `lint:js`, `format`, `packages-update`
    -   Run `pnpm install`; commit `pnpm-lock.yaml`.
    -   Add `.prettierrc` (`"@wordpress/prettier-config"`) and `eslint.config.js` (flat config extending `@wordpress/eslint-plugin`).
    -   **AC**: `pnpm build` creates (empty) output; `pnpm lint:js` runs cleanly.
    -   **Commit**: `chore: add @wordpress/scripts + lint configs`

-   [x] **1.4** Add GitHub Actions CI

    -   `.github/workflows/ci.yml` with four jobs:
        -   `php-lint` — matrix over PHP 8.0 / 8.1 / 8.2 / 8.3 / 8.4; runs `composer lint`.
        -   `js-lint` — runs `pnpm lint:js` + `pnpm build`.
        -   `audit` — runs `composer audit` + `pnpm audit --audit-level=high --prod`.
        -   `plugin-check` — runs `WordPress/plugin-check-action@v1` (plugin_repo + security categories), informational (`continue-on-error: true`) until `readme.txt` lands in Phase 12.1.
    -   Triggers: `push` to `main`, any `pull_request`, `workflow_dispatch`. Concurrency group cancels in-progress runs on the same ref.
    -   **AC**: Pushed branch shows green CI on GitHub (`plugin-check` may report findings but does not fail the run).
    -   **Commit**: `ci: add lint, audit, and plugin-check workflow`

-   [x] **1.5** Husky + lint-staged

    -   `pnpm exec husky init`; hook runs `pnpm exec lint-staged`.
    -   `lint-staged`: `*.php` → `vendor/bin/phpcbf`, `*.{js,jsx,json,md,css,yml,yaml}` → `prettier --write --ignore-unknown`.
    -   **AC**: Staging a malformed PHP file and committing auto-fixes it.
    -   **Commit**: `chore: add husky + lint-staged pre-commit hook`

-   [x] **1.6** 🏷️ **Release v0.2.0** — Tooling & developer loop
    -   Bump `Version:` in [logscope.php](logscope.php) to `0.2.0`.
    -   Move `[Unreleased]` block in [CHANGELOG.md](CHANGELOG.md) under a new `[0.2.0] - YYYY-MM-DD` heading; create a fresh `[Unreleased]`.
    -   **AC**: `git tag v0.2.0` succeeds; `git log --oneline` shows the release commit.
    -   **Commit**: `chore(release): v0.2.0`

---

## Phase 2 — Plugin bootstrap

Goal: Plugin activates, wires a DI container, and exposes a stable extension surface. Still no user-visible features.

-   [x] **2.1** `src/Plugin.php` + autoload wire-up

    -   Plugin class with hand-rolled DI container (array-based factory map, lazy instantiation).
    -   `logscope.php` now `require vendor/autoload.php` + `Plugin::boot()`.
    -   Fires `logscope/booted` action.
    -   **AC**: Plugin activates on a fresh WP 6.2 install on PHP 8.0 with zero notices/warnings (`WP_DEBUG=true`, `WP_DEBUG_LOG=true`).
    -   **Commit**: `feat: plugin bootstrap with DI container`

-   [x] **2.2** `Activator` + `Deactivator` + `uninstall.php`

    -   `Activator::activate()`: sets default option values, adds `logscope_manage` cap to administrators.
    -   `Deactivator::deactivate()`: clears scheduled cron events.
    -   `uninstall.php`: removes all `logscope_*` options and transients (works whether plugin is active or not).
    -   **AC**: Activate → deactivate → uninstall leaves no `logscope_*` rows in `wp_options`.
    -   **Commit**: `feat: add activation, deactivation, uninstall lifecycle`

-   [x] **2.3** `src/Support/Capabilities.php`

    -   `has_manage_cap()` helper used everywhere a check is needed.
    -   Filter `logscope/required_capability` for custom role mapping.
    -   **AC**: Unit test covers the helper returning true/false based on `current_user_can` mock.
    -   **Commit**: `feat(support): add capability helper`

-   [x] **2.4** 🏷️ **Release v0.3.0** — Plugin bootstrap + lifecycle
    -   **Commit**: `chore(release): v0.3.0`

---

## Phase 3 — Log reading & parsing (the foundation)

Goal: Prove you can reliably read and parse a real `debug.log` in-memory. **This is the single most important de-risking step** — parsers written wrong haunt the whole project.

-   [x] **3.1** 🔒 `src/Support/PathGuard.php`

    -   Allowlist: `ABSPATH`, `WP_CONTENT_DIR`, the configured log path's parent.
    -   Reject `..` in raw input before `realpath`.
    -   Reject if `realpath` escapes allowlist or is a symlink pointing outside.
    -   **AC**: Unit tests cover: happy path, `../../../etc/passwd`, symlink escape, missing file, readable vs writable checks.
    -   **Commit**: `feat(support): add PathGuard with traversal protection`

-   [x] **3.2** `src/Log/LogSourceInterface.php` + `FileLogSource.php`

    -   Interface: `read_chunk(int $from_byte, int $max_bytes): string`, `size(): int`, `exists(): bool`.
    -   `FileLogSource` uses `fopen`/`fseek`/`fread` (streams, never `file_get_contents` — logs can be huge).
    -   Uses `PathGuard` on construction.
    -   **AC**: Unit test reads a 5MB fixture log and reports correct size + returns expected byte range.
    -   **Commit**: `feat(log): add file-backed log source`

-   [x] **3.3** `src/Log/LogParser.php`

    -   Pure function: `parse(string $chunk): Entry[]`
    -   Detect severity: `Fatal error`, `Parse error`, `Warning`, `Notice`, `Deprecated`, `Strict Standards`.
    -   Parse WP's timestamp format `[DD-Mon-YYYY HH:MM:SS UTC]`.
    -   Detect continuation lines (stack trace rows) and attach to previous entry.
    -   **AC**: Unit tests cover all 6 severity types, timestamps with/without TZ, multi-line PHP fatals, and entries truncated at chunk boundaries.
    -   **Commit**: `feat(log): add log parser`

-   [x] **3.4** `src/Log/StackTraceParser.php`

    -   Parses `#0 /path/to/file.php(123): Class->method()` lines.
    -   Returns `Frame[]` with `file`, `line`, `class`, `method`, `args` (string only, not eval'd).
    -   **AC**: Unit tests on fixtures from real PHP 8 stack traces.
    -   **Commit**: `feat(log): add stack trace parser`

-   [x] **3.5** `src/Log/LogGrouper.php`

    -   Signature = hash of (severity + file + line + normalized message shape — strip quoted strings, numbers, hex addrs).
    -   Groups entries, tracks `first_seen`, `last_seen`, `count`.
    -   **AC**: Unit test — 1000 varied log lines group down to the expected ~N signatures; sort by count desc.
    -   **Commit**: `feat(log): add signature-based grouping`

-   [x] **3.6** `src/Log/LogRepository.php`

    -   Facade over source + parser + grouper. Paginated, filtered access.
    -   Supports filters: severity, date range, regex (server-side, pattern length ≤ 200), source plugin/theme (parsed from file path).
    -   **AC**: Integration test reads a fixture log and returns page 2 of 50 with severity=Fatal applied.
    -   **Commit**: `feat(log): add repository with pagination + filters`

-   [x] **3.7** 🏷️ **Release v0.4.0** — Log reading & parsing foundation
    -   **Commit**: `chore(release): v0.4.0`

> Phase 3 complete on 2026-04-27.

---

## Phase 4 — REST API

Goal: React can fetch everything it needs via `/wp-json/logscope/v1/*`.

-   [x] **4.1** 🔒 `src/REST/RestController.php` (abstract base)

    -   Centralizes capability check (`logscope_manage`), nonce verification, JSON schema registration, error responses.
    -   Every subclass calls `$this->check_permission()` in its `permission_callback`.
    -   **AC**: Unit test — an endpoint extending this base rejects unauthenticated requests with 401 and non-caps users with 403.
    -   **Commit**: `feat(rest): add controller base with cap + nonce enforcement`

-   [x] **4.2** `src/REST/LogsController.php` — `GET /logs`

    -   Query params: `page`, `per_page`, `severity`, `from`, `to`, `q` (regex), `grouped` (bool), `source`.
    -   Returns paginated entries with `X-WP-Total` / `X-WP-TotalPages` headers.
    -   **AC**: Integration test hits the endpoint with a mock WP install and asserts shape + pagination.
    -   **Commit**: `feat(rest): add GET /logs endpoint`

-   [x] **4.3** `LogsController` — `DELETE /logs` (Clear log) + `GET /logs/download`
    -   **Clear log default: soft-delete** (rename `debug.log` → `debug.log.cleared-YYYYMMDD-HHMMSS`). Resolves open question from AGENTS.md §11.
    -   Confirmation via `?confirm=true` query param required.
    -   Download streams the file with `Content-Disposition: attachment`.
    -   **AC**: Unit tests on the soft-delete rename logic; integration test on download headers.
    -   **Commit**: `feat(rest): add log clear (soft-delete) and download`

---

## Phase 5 — Settings

-   [x] **5.1** `src/Settings/SettingsSchema.php` + `Settings.php`

    -   Single source of truth: fields, defaults, sanitizers, types.
    -   `Settings::get($key)` / `Settings::set($key, $value)` — sanitizes on set.
    -   Fields for v1.0: `log_path`, `tail_interval` (default 3s, min 1s). Alert fields (`alert_email_*`, `alert_webhook_*`, `alert_dedup_window`) are added in v1.1.0 with the Alerts release.
    -   **AC**: Unit test — set invalid `tail_interval=0` → coerces to 1; unknown keys rejected.
    -   **Commit**: `feat(settings): add schema-driven settings`

-   [x] **5.2** `src/REST/SettingsController.php` — `GET` + `POST /settings`

    -   Uses `SettingsSchema` to validate incoming payload.
    -   Returns full settings shape on GET.
    -   **AC**: Integration test — POST with extra keys is rejected; valid POST persists + returns new state.
    -   **Commit**: `feat(rest): add settings endpoints`

-   [x] **5.3** 🏷️ **Release v0.5.0** — REST API + settings backend
    -   **Commit**: `chore(release): v0.5.0`

> Phase 5 complete on 2026-04-27.

---

## Phase 6 — React admin UI (shell)

Goal: A mount point under **Tools → Logscope** that renders the log viewer.

-   [x] **6.1** `src/Admin/Menu.php` + `PageRenderer.php`

    -   Registers submenu under `tools.php`, capability `logscope_manage`.
    -   Renders `<div id="logscope-root"></div>`.
    -   **AC**: Menu item visible to admin; hidden from subscribers.
    -   **Commit**: `feat(admin): add Tools → Logscope menu`

-   [x] **6.2** `src/Admin/AssetLoader.php`

    -   Enqueues `assets/build/index.js` + `.css` **only on the Logscope screen** (check `get_current_screen()`).
    -   Uses the `.asset.php` file generated by `@wordpress/scripts` for dep hashes.
    -   Localizes: REST URL, nonce, current user caps, i18n strings.
    -   **AC**: View source on Logscope page shows bundle; view source on Dashboard does not.
    -   **Commit**: `feat(admin): enqueue React bundle on plugin page only`

-   [x] **6.3** React app skeleton (`assets/src/index.js`, `App.jsx`)

    -   Mounts into `#logscope-root`.
    -   `@wordpress/data` store registered at `logscope/core`.
    -   REST client wrapper (`assets/src/api/client.js`) using `@wordpress/api-fetch`.
    -   Tab layout: Logs · Settings.
    -   **AC**: `pnpm build` + page loads shows "Logscope" with two tabs. No console errors.
    -   **Commit**: `feat(ui): React app shell with tabs and store`

-   [x] **6.4** `LogViewer` component (virtualized list)

    -   Use `react-window` (or a minimal hand-rolled virtualizer — choose based on bundle size) for 10k+ rows.
    -   `EntryRow` renders one line with severity pill, timestamp, truncated message, "show trace" toggle.
    -   Empty state when no logs exist.
    -   **AC**: Renders 5000-entry fixture smoothly (no jank at 60fps when scrolling).
    -   **Commit**: `feat(ui): virtualized log viewer`

-   [x] **6.5** 🏷️ **Release v0.6.0** — Admin page + React viewer shell
    -   **Commit**: `chore(release): v0.6.0`

> Phase 6 complete on 2026-04-27.

---

## Phase 7 — Filters, grouping, trace expansion

-   [x] **7.1** `FilterBar` component

    -   Severity multi-select, date-range picker, regex search, source dropdown (populated from distinct paths in current result set).
    -   Debounce regex input (300ms).
    -   Writes to store; store triggers REST refetch.
    -   **AC**: Changing any filter updates the URL query string + refetches.
    -   **Commit**: `feat(ui): filter bar (severity, date, regex, source)`

-   [x] **7.2** `GroupedView` component

    -   Toggle between "list" and "grouped" modes.
    -   Grouped shows signature, count, first_seen, last_seen, expandable to show all matching entries.
    -   **AC**: Toggling preserves filters and scroll position.
    -   **Commit**: `feat(ui): grouped error view`

-   [x] **7.3** `StackTracePanel` component

    -   Expand/collapse per entry.
    -   Each frame: clickable copy-to-clipboard of file:line.
    -   **AC**: Click frame → clipboard contains exact `path/to/file.php:123`.
    -   **Commit**: `feat(ui): stack trace panel with copy`

-   [x] **7.4** Tail mode

    -   Toggle in toolbar; when active, polls `/logs?since=<last_byte>` every `tail_interval` seconds.
    -   Auto-scrolls to bottom unless user has scrolled up (then shows "N new" pill).
    -   **AC**: Appending a line to `debug.log` appears in the viewer within `tail_interval` seconds.
    -   **Commit**: `feat(ui): tail mode with polling`

-   [x] **7.5** 🏷️ **Release v0.7.0** — Filters, grouping, trace, tail
    -   **Commit**: `chore(release): v0.7.0`

> Phase 7 complete on 2026-04-27.

---

## Phase 8 — Settings UI

-   [x] **8.1** `SettingsPanel` component

    -   Fields match `SettingsSchema`. Uses `@wordpress/components` (TextControl, ToggleControl, etc.).
    -   "Save" button + inline validation messages from REST response.
    -   **AC**: Editing `tail_interval`, saving, reloading — value persists.
    -   **Commit**: `feat(ui): settings panel`

-   [x] **8.2** Custom log path UI + validation

    -   Field shows the resolved absolute path and a "Test" button (hits a REST route that runs `PathGuard` without side effects).
    -   Shows clear error when path is rejected ("outside allowed directories").
    -   **AC**: Entering `../../../etc/passwd` shows a rejection message.
    -   **Commit**: `feat(ui): custom log path with test button`

-   [x] **8.3** 🏷️ **Release v0.8.0** — Settings UI + custom log path
    -   **Commit**: `chore(release): v0.8.0`

> Phase 8 complete on 2026-04-28.

---

## Phase 11 — Polish & accessibility

> Phases 9 (Alerts) and 10 (Cron scanner) are **deferred to post-1.0** (v1.1.0 and v1.2.0 — see below). Keeping phase numbers stable so existing references don't break.

-   [x] **11.1** Loading skeletons, empty states, error toasts throughout

    -   **Commit**: `feat(ui): loading skeletons, empty states, error toasts`

-   [x] **11.2** Keyboard shortcuts: `/` focus filter, `g` toggle grouped, `t` toggle tail, `?` help modal

    -   **Commit**: `feat(ui): keyboard shortcuts`

-   [x] **11.3** Full accessibility pass: axe-core clean, keyboard nav for all interactive elements, `aria-live` for tail updates

    -   **Commit**: `feat(ui): accessibility pass`

-   [x] **11.4** Dark mode parity (WP admin dark mode extensions — respect `prefers-color-scheme` + WP admin schemes)

    -   **Commit**: `feat(ui): dark mode parity`

-   [x] **11.5** i18n pass: every user-facing string wrapped, generate `languages/logscope.pot` via `wp i18n make-pot`

    -   **Commit**: `feat(i18n): wrap strings and generate .pot`

-   [x] **11.6** 🏷️ **Release v0.9.0** — Polish, a11y, i18n
    -   **Commit**: `chore(release): v0.9.0`

> Phase 11 complete on 2026-04-28.

---

## Phase 12 — Alerts (v0.10.0)

Goal: Admins get notified about new fatals without watching the log. Email + webhook dispatchers, signature-keyed dedup so a single error doesn't fire 500 emails.

-   [x] **12.1** `src/Alerts/AlertDispatcherInterface.php` + `Alerts/AlertDeduplicator.php`

    -   Interface: `dispatch(Group $group): void`, `name(): string`.
    -   Dedup: transient keyed by `signature_hash | dispatcher_name`; TTL = configured window (default 300s); `should_send()` / `record_sent()` round-trip.
    -   **AC**: Unit tests cover dedup window expiry, distinct dispatchers don't share window, and signature collisions across dispatchers are independent.
    -   **Commit**: `feat(alerts): dispatcher interface and signature-keyed dedup`

-   [x] **12.2** `src/Alerts/EmailAlerter.php`

    -   Uses `wp_mail()` with `text/html` content-type filter and a plaintext fallback body assembled by stripping tags.
    -   Subject template: `[Logscope] <Severity> on <site_name>: <short_msg>` (60-char truncation on `short_msg`).
    -   `logscope/email_subject` and `logscope/email_body` filters for site-owner customisation.
    -   **AC**: Unit test (Brain Monkey) — `wp_mail` called with expected to/subject/body shape; html + plaintext both built; filters honoured.
    -   **Commit**: `feat(alerts): email dispatcher`

-   [x] **12.3** 🔒 `src/Alerts/WebhookAlerter.php`

    -   Uses `wp_remote_post()` with `timeout: 5`, `blocking: true`, `redirection: 0` (don't follow redirects to internal hosts).
    -   Neutral JSON payload: `{site, severity, message, file, line, signature, first_seen, last_seen, count, url}`.
    -   `logscope/webhook_payload` filter lets users reshape for Slack/Discord/Teams.
    -   URL allowlist enforcement: must be `http://` or `https://`, no `file://` / `gopher://` / etc; `wp_http_validate_url()` is the gatekeeper.
    -   **AC**: Unit test — `wp_remote_post` called with the right shape; non-2xx responses are recorded but don't throw; non-http(s) URLs rejected before send.
    -   **Commit**: `feat(alerts): webhook dispatcher with neutral payload`

-   [x] **12.4** `src/Alerts/AlertCoordinator.php`

    -   Iterates registered dispatchers, applies dedup per-dispatcher (so a webhook can fire while email is rate-limited and vice versa), fires `logscope/before_alert` (filterable; return `false` to skip) and `logscope/alert_sent` (action) around each dispatch.
    -   `dispatch_for_groups(Group[] $groups)` takes the LogRepository's grouped output directly.
    -   **AC**: Unit test — disabled dispatchers skipped; `before_alert` returning `false` short-circuits cleanly; `alert_sent` fires with `(group, dispatcher_name)`.
    -   **Commit**: `feat(alerts): coordinator with fanout + per-dispatcher dedup`

-   [x] **12.5** SettingsSchema extensions

    -   New fields: `alert_email_enabled` (bool, default false), `alert_email_to` (sanitise via `sanitize_email`), `alert_webhook_enabled` (bool), `alert_webhook_url` (sanitise via `esc_url_raw` + protocol allowlist), `alert_dedup_window` (int seconds, default 300, min 60).
    -   `Activator::DEFAULT_OPTIONS` updated to seed the new keys (cross-link comment kept in sync).
    -   **AC**: Unit test — invalid email coerces to empty string; non-http URL coerces to empty; dedup window < 60 coerces to 60.
    -   **Commit**: `feat(settings): add alert fields to schema`

-   [x] **12.6** 🔒 `src/REST/AlertsController.php` — `POST /alerts/test`

    -   Bypasses dedup; sends one synthetic alert to every enabled dispatcher.
    -   Returns per-dispatcher result: `{dispatcher, ok, error?}`.
    -   Capability check via the abstract base; nonce enforced as on every other Logscope route.
    -   **AC**: Integration test — POST with email enabled returns `ok:true` for email; POST with both disabled returns 400 `logscope_rest_no_alerters_enabled`.
    -   **Commit**: `feat(rest): add POST /alerts/test endpoint`

-   [x] **12.7** React `AlertsPanel` in Settings tab

    -   New section under existing settings form: email toggle + recipient `TextControl`, webhook toggle + URL `TextControl`, dedup window slider/number input, "Send test alert" button.
    -   Test result toast shows per-dispatcher outcome.
    -   Inline validation: invalid email shows under the field; invalid URL shows under the field. Validation messages from REST `fieldErrors` shape established in Phase 8.
    -   **AC**: Hand-test on a WP install — change email, save, hit "Send test alert", receive the email.
    -   **Commit**: `feat(ui): alerts settings panel with test-send`

-   [x] **12.8** 🔒 `security-review` skill pass

    -   Scope: `WebhookAlerter` (URL validation, redirect handling, payload escaping), `/alerts/test` route (rate limiting? cap check), email subject/body building (no header injection).
    -   **AC**: All HIGH / MEDIUM findings resolved (fix or documented non-issue).
    -   **Commit(s)**: one per fix as needed.

-   [x] **12.9** 🏷️ **Release v0.10.0** — Alerts
    -   Bump `Version:` in [logscope.php](logscope.php) to `0.10.0`.
    -   Roll `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md) under `[0.10.0] - YYYY-MM-DD`; refresh link references.
    -   Update [README.md](README.md) status line.
    -   **Commit**: `chore(release): v0.10.0`

> Phase 12 complete on 2026-04-30.

---

## Phase 13 — Scheduled fatal scanner (v0.11.0)

Goal: Background cron scans the log for new fatals and feeds the AlertCoordinator without the admin needing to open the page.

-   [x] **13.1** `src/Cron/LogScanner.php`

    -   Registered event `logscope_scan_fatals` (filter `logscope/scan_interval` for cadence; default 5 min).
    -   Reads log since `logscope_last_scanned_byte` option, parses, filters to fatals + parse errors, groups, feeds to `AlertCoordinator::dispatch_for_groups()`.
    -   Updates `logscope_last_scanned_byte` and `logscope_last_scanned_at` after a successful run.
    -   Handles rotation: if current `last_byte < last_scanned_byte`, treat as rotation and reset to 0.
    -   **AC**: Unit test — given a fixture log with two fatals, scanner calls coordinator once with two groups; second invocation with no new bytes is a no-op.
    -   **Commit**: `feat(cron): scheduled fatal-error scanner`

-   [x] **13.2** `Activator` / `Deactivator` cron lifecycle

    -   `Activator::activate()` schedules `logscope_scan_fatals` if cron is enabled (default off — opt-in to avoid cron noise on fresh install).
    -   `Deactivator::deactivate()` calls `wp_clear_scheduled_hook('logscope_scan_fatals')`.
    -   `Settings::set('cron_scan_enabled', …)` re-schedules / unschedules on toggle.
    -   **AC**: Unit test — enabling the option calls `wp_schedule_event`; disabling calls `wp_clear_scheduled_hook`.
    -   **Commit**: `feat(cron): wire scanner into activation lifecycle`

-   [x] **13.3** SettingsSchema extensions

    -   `cron_scan_enabled` (bool, default false), `cron_scan_interval_minutes` (int, default 5, min 1, max 1440).
    -   Custom interval registered via `cron_schedules` filter so admin choices map to a real schedule.
    -   **AC**: Unit test — schedule appears in `wp_get_schedules()` after plugin boot.
    -   **Commit**: `feat(settings): add cron scanner fields`

-   [x] **13.4** React Settings UI for cron

    -   Toggle + interval input + read-only "Last scan: <relative time> · <N> fatals dispatched" status row (read from `logscope_last_scanned_at` + a per-run summary option).
    -   **AC**: Hand-test — enabling the toggle, waiting for one tick, sees the status update.
    -   **Commit**: `feat(ui): cron scanner settings`

-   [x] **13.5** Test-only manual-trigger guard

    -   Brain Monkey records `add_action` / `do_action` for assertions but does not actually invoke registered callbacks. The integration test stubs both onto a per-test callback registry so `do_action('logscope_scan_fatals')` drives `LogScanner::scan()` end-to-end the same way WP-Cron would in production.
    -   **AC**: Integration test calls `do_action('logscope_scan_fatals')` against a fixture log and observes the alert pipeline receive the parsed groups; second invocation with no new bytes is a no-op.
    -   **Commit**: `test(cron): integration test driving scanner via do_action`

-   [x] **13.6** 🏷️ **Release v0.11.0** — Scheduled fatal scanner
    -   **Commit**: `chore(release): v0.11.0`

> Phase 13 complete on 2026-04-30.

---

## Phase 14 — Retention, mute, filter presets (v0.12.0)

Goal: Long-running sites don't accumulate 200MB log files; admins can hide known noise; power users save filter combinations.

### Log retention

-   [x] **14.1** `src/Log/LogRotator.php`

    -   Archive when `FileLogSource::size() > retention_max_size_mb * 1024 * 1024` by renaming `debug.log` → `debug.log.archived-YYYYMMDD-HHMMSS` (UTC).
    -   Prune oldest archives beyond `retention_max_archives` (default 5) — `unlink()` after sorting matching siblings by mtime.
    -   Uses `PathGuard::is_writable_parent_of()` before the rename + before each `unlink`.
    -   **AC**: Unit test — fixture with 6 archives prunes oldest 1; size below threshold is a no-op.
    -   **Commit**: `feat(log): add size-based log rotator`

-   [x] **14.2** SettingsSchema additions

    -   `retention_enabled` (bool, default false), `retention_max_size_mb` (int, default 50, min 1, max 1024), `retention_max_archives` (int, default 5, min 1, max 50).
    -   **Commit**: `feat(settings): add retention fields`

-   [x] **14.3** Cron event `logscope_rotate_logs` (daily) invoking `LogRotator`

    -   `Activator` schedules it on activation if `retention_enabled` is true; toggling the option re-schedules.
    -   **AC**: Unit test — enabling retention calls `wp_schedule_event` with `daily`.
    -   **Commit**: `feat(cron): schedule daily log rotation`

### Mute signatures

-   [x] **14.4** Mute store

    -   New option `logscope_muted_signatures` — array of `{signature, reason, muted_at, muted_by}`.
    -   `Logscope\Log\MuteStore` service: `add($sig, $reason, $user_id)`, `remove($sig)`, `list()`, `is_muted($sig)`.
    -   **AC**: Unit test — adding the same signature twice updates rather than duplicates.
    -   **Commit**: `feat(log): add mute store for signatures`

-   [x] **14.5** REST `POST /logs/mute` + `DELETE /logs/mute/<signature>` + `GET /logs/mute`

    -   POST body: `{signature, reason}`. DELETE by URL path. GET returns the full list.
    -   **AC**: Integration tests on all three verbs.
    -   **Commit**: `feat(rest): add mute endpoints`

-   [x] **14.6** `LogRepository` filters out muted signatures by default

    -   New `LogQuery::$include_muted` flag (default false). When false, ungrouped queries skip entries whose computed signature is in the mute list, and grouped queries omit muted groups entirely.
    -   `LogsController` accepts `?include_muted=true` to expose muted entries (used by the management UI).
    -   **AC**: Integration test — mute a signature; default `/logs` doesn't return it; `/logs?include_muted=true` does.
    -   **Commit**: `feat(log): filter muted signatures from default queries`

-   [x] **14.7** UI: "Mute" button + management panel

    -   Grouped row `EntryRow`: "Mute" button → modal asking for optional reason → POST `/logs/mute`.
    -   Settings tab: new "Muted signatures" panel listing all muted entries with "Unmute" buttons.
    -   **AC**: Hand-test mute/unmute round trip; muted group disappears from grouped view immediately.
    -   **Commit**: `feat(ui): mute/unmute UI`

### Filter presets

-   [x] **14.8** Saved filter presets

    -   Stored in user-meta `logscope_filter_presets` (per-user; collaboration on a multi-admin site shouldn't surface a colleague's presets).
    -   Shape: `[{name, filters: {severity, from, to, q, source, viewMode}}, …]`.
    -   **Commit**: `feat(settings): add per-user filter preset store`

-   [x] **14.9** REST `GET /presets` + `POST /presets` + `DELETE /presets/<name>`

    -   GET returns current user's presets. POST creates / overwrites by name. DELETE removes by name.
    -   **AC**: Integration tests on all three.
    -   **Commit**: `feat(rest): add filter preset endpoints`

-   [x] **14.10** UI: preset dropdown in FilterBar

    -   "Save current filters as preset" → name prompt → POST.
    -   "Load preset" dropdown lists user's presets; selecting one populates the FilterBar slice + URL.
    -   "Delete preset" inline x-button.
    -   **AC**: Hand-test save → reload page → preset still loadable.
    -   **Commit**: `feat(ui): filter preset save/load UI`

-   [x] **14.11** 🏷️ **Release v0.12.0** — Retention, mute, presets
    -   **Commit**: `chore(release): v0.12.0`

> Phase 14 complete on 2026-04-30.

---

## Phase 15 — Stats dashboard (v0.13.0)

Goal: A "Stats" tab that gives an at-a-glance view of error frequency over time without leaving wp-admin.

-   [x] **15.1** `src/Log/LogStats.php`

    -   Time-bucketed aggregations over the current log: counts per severity per `hour` or `day`, configurable range (`24h`, `7d`, `30d`).
    -   Caches per (log size, mtime, range, bucket) in a transient with 60s TTL — re-parsing 50MB on every tab open is wasteful.
    -   **AC**: Unit test — fixture with known severity distribution returns expected bucketed counts; mtime-changed invalidates cache.
    -   **Commit**: `feat(log): add stats aggregation service`

-   [x] **15.2** REST `GET /stats?range=24h|7d|30d&bucket=hour|day`

    -   Returns `{range, bucket, buckets: [{ts, fatal, warning, notice, …}], totals: {…}, top: [{signature, count, sample}]}`.
    -   **AC**: Integration test — fixture log returns expected bucket count + top-N.
    -   **Commit**: `feat(rest): add GET /stats endpoint`

-   [x] **15.3** New "Stats" React tab

    -   Third tab next to Logs / Settings. Tab order: Logs · Stats · Settings (Stats slots between because it shares filter context).
    -   `@wordpress/data` `stats` slice with the same draft/values shape as settings.
    -   **Commit**: `feat(ui): add Stats tab scaffold`

-   [x] **15.4** Sparkline charts per severity

    -   Hand-rolled SVG (no chart library — bundle weight stays under 20KB gz). One sparkline per severity in a small-multiple grid.
    -   `aria-label` per chart describing peak / mean / total for the range; the SVG itself is `aria-hidden`.
    -   **AC**: Smooth render on a 30-day fixture; passes axe-core.
    -   **Commit**: `feat(ui): severity sparklines`

-   [ ] **15.5** Top-10 signatures table

    -   Click-through dispatches to the Logs tab with the FilterBar pre-populated to the clicked signature's severity + a regex matching its message.
    -   **AC**: Click → tab switch → Logs view filtered.
    -   **Commit**: `feat(ui): top signatures table with click-through`

-   [ ] **15.6** Severity breakdown bar (range totals)

    -   Single horizontal stacked bar showing the proportion of each severity over the selected range.
    -   **Commit**: `feat(ui): severity breakdown bar`

-   [ ] **15.7** 🏷️ **Release v0.13.0** — Stats dashboard
    -   **Commit**: `chore(release): v0.13.0`

---

## Phase 16 — Onboarding, diagnostics, bulk actions (v0.14.0)

Goal: A first-time user opening the plugin with `WP_DEBUG_LOG` off shouldn't see a silently empty page; admins triaging a flood can act on multiple groups at once.

-   [ ] **16.1** `src/Support/DiagnosticsService.php`

    -   Detects: `WP_DEBUG`, `WP_DEBUG_LOG`, resolved log path (via PathGuard), parent writability, file existence, file size, last-modified time.
    -   Returns a structured snapshot — every field is a typed boolean / int / string, no nulls.
    -   **AC**: Unit test — both flags off → all-false snapshot; flag on but file missing → `exists:false, parent_writable: <bool>`.
    -   **Commit**: `feat(support): add diagnostics service`

-   [ ] **16.2** REST `GET /diagnostics`

    -   Capability-gated; returns the snapshot.
    -   **AC**: Integration test — endpoint returns the snapshot shape.
    -   **Commit**: `feat(rest): add GET /diagnostics endpoint`

-   [ ] **16.3** Onboarding banner on Logs tab when `WP_DEBUG_LOG` is off

    -   Dismissible-per-session banner above the FilterBar with manual `wp-config.php` instructions (no auto-edit — that's deferred to post-1.0).
    -   Banner copy includes the exact lines to add and links to the WP handbook.
    -   **AC**: Banner shows when REST `/diagnostics` reports `wp_debug_log: false`; hidden otherwise.
    -   **Commit**: `feat(ui): onboarding banner for missing WP_DEBUG_LOG`

-   [ ] **16.4** Empty-log diagnostics

    -   Replace the generic "No log entries" empty state with a reason-aware message: file doesn't exist, file empty, file rotated since last check, all entries muted, etc.
    -   Reuses the diagnostics snapshot rather than firing a separate request.
    -   **AC**: Manually delete `debug.log` → empty state explains "log file does not yet exist at <path>".
    -   **Commit**: `feat(ui): reason-aware empty log state`

-   [ ] **16.5** Bulk actions in grouped view

    -   Per-group checkbox + "Select all" header checkbox.
    -   Bulk action bar appears when ≥1 group is selected: "Mute selected" (uses Phase 14 mute), "Export selected" (CSV download via existing infrastructure).
    -   **AC**: Hand-test — select 3 groups, click "Mute selected", all 3 disappear from view.
    -   **Commit**: `feat(ui): bulk actions in grouped view`

-   [ ] **16.6** 🏷️ **Release v0.14.0** — Onboarding, diagnostics, bulk actions
    -   **Commit**: `chore(release): v0.14.0`

---

## Phase 17 — 🚀 wp.org release line (v1.0.0)

**This is the only phase where code leaves the repo for public distribution.** Phase 17 deliberately stretches: prep infrastructure ships first under `v0.15.0`, then any pre-1.0 changes ship under their own `v0.x.0` bumps, and the `v1.0.0` cut is gated on a concrete "we're done" decision rather than a date. Do not skip or reorder steps within each subsection.

### Pre-1.0 release infrastructure

-   [ ] **17.1** `readme.txt` (wp.org format — Contributors, Tags, Requires at least, Tested up to, Stable tag, Requires PHP, License, License URI, Description, Installation, FAQ, Changelog, Screenshots, Privacy)

    -   **Commit**: `docs: add wp.org readme.txt`

-   [ ] **17.2** `.wordpress-org/` assets: banner-1544x500.png, icon-256x256.png, 5–7 screenshots (log viewer, grouped view, filters, stats, alerts settings, mute panel, onboarding banner)

    -   **Commit**: `docs: add wp.org banner, icon, screenshots`

-   [ ] **17.3** Release workflow `.github/workflows/release.yml`: build zip, strip dev deps (honor `.gitattributes export-ignore`), tag → upload asset

    -   **AC**: Dry-run on a throwaway tag produces a zip with no `vendor/`, no `node_modules/`, no `tests/`, no `.github/`.
    -   **Commit**: `ci: add release workflow`

-   [ ] **17.4** 🏷️ **Release v0.15.0** — Pre-1.0 release infrastructure
    -   **Commit**: `chore(release): v0.15.0`

### Pre-1.0 changes

Open-ended placeholder for the work that has to land before the wp.org cut. Fill in concrete sub-steps as decisions are made (one box per behavioural commit, one bump step at the end of each shippable bundle). Each pre-1.0 change release is a regular `0.x.0` bump — local git tag only, nothing leaves the repo.

-   [ ] **17.5** Pre-1.0 changes — _(open: list features / fixes / refactors here as they're decided)_

-   [ ] **17.6** 🏷️ **Release v0.x.0** — Pre-1.0 changes
    -   Final `0.x.0` bump that closes step 17.5. Increment from the last shipped version. If 17.5 grows multiple bump points, split it and number `17.6a`, `17.6b`, … as needed.
    -   **Commit**: `chore(release): v0.x.0`

### 🔒 Security gate

-   [ ] **17.7** 🔒 Full `security-review` skill pass across:
    -   `PathGuard` (traversal, symlink escape, allowlist)
    -   REST auth (every route has cap + nonce; abstract base enforces it)
    -   Uninstall cleanup (`uninstall.php` deletes every `logscope_*` option + transient + user-meta + scheduled cron)
    -   Log clear soft-delete + log rotation (no arbitrary rename targets)
    -   Webhook handling (URL allowlist, no SSRF, payload escaping)
    -   Output escaping on anything read from disk and returned via REST
    -   **AC**: Every finding from the skill is resolved (fixed or documented as non-issue). No outstanding `HIGH` or `MEDIUM` items.
    -   **Commit(s)**: one per fix, as needed

### Cut v1.0.0

-   [ ] **17.8** Bump version to **1.0.0**

    -   [logscope.php](logscope.php) header `Version: 1.0.0`
    -   [readme.txt](readme.txt) `Stable tag: 1.0.0`, `Tested up to:` = current stable WP
    -   [CHANGELOG.md](CHANGELOG.md) — move `[Unreleased]` under `[1.0.0] - YYYY-MM-DD`
    -   **Commit**: `chore(release): v1.0.0`

-   [ ] **17.9** Tag `v1.0.0`, push tag, let the release workflow build the zip

    -   **AC**: GitHub release page shows the zip asset.

-   [ ] **17.10** **Submit plugin to wp.org plugin directory**

    -   Upload the zip via <https://wordpress.org/plugins/developers/add/>.
    -   After reviewer approval, push to the wp.org SVN `trunk/` + tag `tags/1.0.0/`.
    -   **AC**: Plugin page live at `https://wordpress.org/plugins/logscope/`; "Install Now" works on a clean WP site.

-   [ ] **17.11** Post-release note at top of this file: `> v1.0.0 shipped to wp.org on YYYY-MM-DD.` Close out v1.0 open issues on GitHub.

---

## Post-1.0 — keep-adding cadence

Each version below is a single coherent release. Flow for every one:

1. Branch → implement → PR → merge to `main`.
2. Run `security-review` skill if the release touches REST, file I/O, or external HTTP.
3. Bump version, update `CHANGELOG.md`, tag, push.
4. Push to wp.org SVN (`trunk/` + `tags/X.Y.Z/`), update `Stable tag:` in `trunk/readme.txt`.

### v1.1.0 — Live streaming

-   SSE or WebSocket replacement for tail-mode polling. Feature-flag in settings, polling stays as fallback. Ship only after measuring on a real WP host — some shared hosts kill long-running PHP.

### v1.2.0 — Multisite aggregation

-   Network admin screen, per-site switch, network-wide capability mapping. Revisit `PathGuard` allowlist semantics for network content dirs.

### v1.3.0 — Auto-edit `wp-config.php` for `WP_DEBUG_LOG`

-   Onboarding flow that offers to flip the constant for the admin (deferred from Phase 16). Gated behind a confirmation step + a backup of `wp-config.php` to `wp-config.php.logscope-backup-<ts>` before any edit.

### v1.4.0+ — Candidates (un-ordered, pick by demand)

-   Source code preview inline (click stack frame, see file at line N — security-sensitive, reuses PathGuard)
-   Request context capture (URL/method/user at error time, requires MU-style hook)
-   First-class Slack & Discord webhook formatters (bundled, not just filter-based)
-   Opt-in integrations with Loki / Elastic (external HTTP, gated behind explicit user config)
-   Filter preset import / export across users
-   Per-site export / import of all settings as a JSON profile

---

## Tracking tips

-   **One commit per checked box** is the ideal. If a step grows beyond that, split the step.
-   Update `CHANGELOG.md` `[Unreleased]` as you go — don't leave it for release day. The version-bump steps are almost free when `[Unreleased]` is already filled in.
-   When a phase completes, write a brief note here: `> Phase N complete on YYYY-MM-DD` — motivating and useful later.
-   **Pre-1.0 tags are git-only.** Do not push any tag to wp.org SVN until step 12.7. After that, every `v1.x.y` tag gets an SVN push.
-   If a step unblocks rethinking an earlier decision, edit this file. **This roadmap is living.**
