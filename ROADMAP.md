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

| Version    | Closes              | Theme                                   | Public? |
| ---------- | ------------------- | --------------------------------------- | ------- |
| 0.1.0      | Phase 0 ✅          | Scaffold                                | no      |
| 0.2.0      | Phase 1             | Tooling & developer loop                | no      |
| 0.3.0      | Phase 2             | Plugin bootstrap + lifecycle            | no      |
| 0.4.0      | Phase 3             | Log reading & parsing foundation        | no      |
| 0.5.0      | Phases 4 + 5        | REST API + settings backend             | no      |
| 0.6.0      | Phase 6             | Admin page + React viewer shell         | no      |
| 0.7.0      | Phase 7             | Filters, grouping, trace, tail          | no      |
| 0.8.0      | Phase 8             | Settings UI + custom log path           | no      |
| 0.9.0      | Phase 11            | Polish, a11y, i18n (.pot)               | no      |
| 1.0.0-rc.1 | Phase 12.1–12.3     | Release candidate build                 | no      |
| **1.0.0**  | **Phase 12.4–12.8** | **🚀 wp.org submission**                | **YES** |
| 1.1.0      | Post-1.0            | Alerts (email + webhook + dedup)        | YES     |
| 1.2.0      | Post-1.0            | Scheduled fatal scanner (cron)          | YES     |
| 1.3.0+     | Post-1.0            | Live streaming, multisite, retention, … | YES     |

Pre-1.0 bumps are git tags only — nothing leaves the repo. **The wp.org release line is v1.0.0 and only v1.0.0.**

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

-   [ ] **6.2** `src/Admin/AssetLoader.php`

    -   Enqueues `assets/build/index.js` + `.css` **only on the Logscope screen** (check `get_current_screen()`).
    -   Uses the `.asset.php` file generated by `@wordpress/scripts` for dep hashes.
    -   Localizes: REST URL, nonce, current user caps, i18n strings.
    -   **AC**: View source on Logscope page shows bundle; view source on Dashboard does not.
    -   **Commit**: `feat(admin): enqueue React bundle on plugin page only`

-   [ ] **6.3** React app skeleton (`assets/src/index.js`, `App.jsx`)

    -   Mounts into `#logscope-root`.
    -   `@wordpress/data` store registered at `logscope/core`.
    -   REST client wrapper (`assets/src/api/client.js`) using `@wordpress/api-fetch`.
    -   Tab layout: Logs · Settings.
    -   **AC**: `pnpm build` + page loads shows "Logscope" with two tabs. No console errors.
    -   **Commit**: `feat(ui): React app shell with tabs and store`

-   [ ] **6.4** `LogViewer` component (virtualized list)

    -   Use `react-window` (or a minimal hand-rolled virtualizer — choose based on bundle size) for 10k+ rows.
    -   `EntryRow` renders one line with severity pill, timestamp, truncated message, "show trace" toggle.
    -   Empty state when no logs exist.
    -   **AC**: Renders 5000-entry fixture smoothly (no jank at 60fps when scrolling).
    -   **Commit**: `feat(ui): virtualized log viewer`

-   [ ] **6.5** 🏷️ **Release v0.6.0** — Admin page + React viewer shell
    -   **Commit**: `chore(release): v0.6.0`

---

## Phase 7 — Filters, grouping, trace expansion

-   [ ] **7.1** `FilterBar` component

    -   Severity multi-select, date-range picker, regex search, source dropdown (populated from distinct paths in current result set).
    -   Debounce regex input (300ms).
    -   Writes to store; store triggers REST refetch.
    -   **AC**: Changing any filter updates the URL query string + refetches.
    -   **Commit**: `feat(ui): filter bar (severity, date, regex, source)`

-   [ ] **7.2** `GroupedView` component

    -   Toggle between "list" and "grouped" modes.
    -   Grouped shows signature, count, first_seen, last_seen, expandable to show all matching entries.
    -   **AC**: Toggling preserves filters and scroll position.
    -   **Commit**: `feat(ui): grouped error view`

-   [ ] **7.3** `StackTracePanel` component

    -   Expand/collapse per entry.
    -   Each frame: clickable copy-to-clipboard of file:line.
    -   **AC**: Click frame → clipboard contains exact `path/to/file.php:123`.
    -   **Commit**: `feat(ui): stack trace panel with copy`

-   [ ] **7.4** Tail mode

    -   Toggle in toolbar; when active, polls `/logs?since=<last_byte>` every `tail_interval` seconds.
    -   Auto-scrolls to bottom unless user has scrolled up (then shows "N new" pill).
    -   **AC**: Appending a line to `debug.log` appears in the viewer within `tail_interval` seconds.
    -   **Commit**: `feat(ui): tail mode with polling`

-   [ ] **7.5** 🏷️ **Release v0.7.0** — Filters, grouping, trace, tail
    -   **Commit**: `chore(release): v0.7.0`

---

## Phase 8 — Settings UI

-   [ ] **8.1** `SettingsPanel` component

    -   Fields match `SettingsSchema`. Uses `@wordpress/components` (TextControl, ToggleControl, etc.).
    -   "Save" button + inline validation messages from REST response.
    -   **AC**: Editing `tail_interval`, saving, reloading — value persists.
    -   **Commit**: `feat(ui): settings panel`

-   [ ] **8.2** Custom log path UI + validation

    -   Field shows the resolved absolute path and a "Test" button (hits a REST route that runs `PathGuard` without side effects).
    -   Shows clear error when path is rejected ("outside allowed directories").
    -   **AC**: Entering `../../../etc/passwd` shows a rejection message.
    -   **Commit**: `feat(ui): custom log path with test button`

-   [ ] **8.3** 🏷️ **Release v0.8.0** — Settings UI + custom log path
    -   **Commit**: `chore(release): v0.8.0`

---

## Phase 11 — Polish & accessibility

> Phases 9 (Alerts) and 10 (Cron scanner) are **deferred to post-1.0** (v1.1.0 and v1.2.0 — see below). Keeping phase numbers stable so existing references don't break.

-   [ ] **11.1** Loading skeletons, empty states, error toasts throughout

    -   **Commit**: `feat(ui): loading skeletons, empty states, error toasts`

-   [ ] **11.2** Keyboard shortcuts: `/` focus filter, `g` toggle grouped, `t` toggle tail, `?` help modal

    -   **Commit**: `feat(ui): keyboard shortcuts`

-   [ ] **11.3** Full accessibility pass: axe-core clean, keyboard nav for all interactive elements, `aria-live` for tail updates

    -   **Commit**: `feat(ui): accessibility pass`

-   [ ] **11.4** Dark mode parity (WP admin dark mode extensions — respect `prefers-color-scheme` + WP admin schemes)

    -   **Commit**: `feat(ui): dark mode parity`

-   [ ] **11.5** i18n pass: every user-facing string wrapped, generate `languages/logscope.pot` via `wp i18n make-pot`

    -   **Commit**: `feat(i18n): wrap strings and generate .pot`

-   [ ] **11.6** 🏷️ **Release v0.9.0** — Polish, a11y, i18n
    -   **Commit**: `chore(release): v0.9.0`

---

## Phase 12 — 🚀 wp.org release line (v1.0.0)

**This is the only phase where code leaves the repo for public distribution.** Everything before this is local-only git tags. Do not skip or reorder steps.

### Release candidate

-   [ ] **12.1** `readme.txt` (wp.org format — Contributors, Tags, Requires at least, Tested up to, Stable tag, Requires PHP, License, License URI, Description, Installation, FAQ, Changelog, Screenshots, Privacy)

    -   **Commit**: `docs: add wp.org readme.txt`

-   [ ] **12.2** `.wordpress-org/` assets: banner-1544x500.png, icon-256x256.png, 3–5 screenshots (log viewer, grouped view, filters, settings, custom log path)

    -   **Commit**: `docs: add wp.org banner, icon, screenshots`

-   [ ] **12.3** Release workflow `.github/workflows/release.yml`: build zip, strip dev deps (honor `.gitattributes export-ignore`), tag → upload asset

    -   **AC**: Dry-run on a throwaway tag produces a zip with no `vendor/`, no `node_modules/`, no `tests/`, no `.github/`.
    -   **Commit**: `ci: add release workflow`

-   [ ] **12.3a** 🏷️ **Tag v1.0.0-rc.1** — Release candidate build
    -   **Commit**: `chore(release): v1.0.0-rc.1`

### 🔒 Security gate

-   [ ] **12.4** 🔒 Full `security-review` skill pass across:
    -   `PathGuard` (traversal, symlink escape, allowlist)
    -   REST auth (every route has cap + nonce; abstract base enforces it)
    -   Uninstall cleanup (`uninstall.php` deletes every `logscope_*` option + transient)
    -   Log clear soft-delete (no arbitrary rename target)
    -   Output escaping on anything read from disk and returned via REST
    -   **AC**: Every finding from the skill is resolved (fixed or documented as non-issue). No outstanding `HIGH` or `MEDIUM` items.
    -   **Commit(s)**: one per fix, as needed

### Cut v1.0.0

-   [ ] **12.5** Bump version to **1.0.0**

    -   [logscope.php](logscope.php) header `Version: 1.0.0`
    -   [readme.txt](readme.txt) `Stable tag: 1.0.0`, `Tested up to:` = current stable WP
    -   [CHANGELOG.md](CHANGELOG.md) — move `[Unreleased]` under `[1.0.0] - YYYY-MM-DD`
    -   **Commit**: `chore(release): v1.0.0`

-   [ ] **12.6** Tag `v1.0.0`, push tag, let the release workflow build the zip

    -   **AC**: GitHub release page shows the zip asset.

-   [ ] **12.7** **Submit plugin to wp.org plugin directory**

    -   Upload the zip via <https://wordpress.org/plugins/developers/add/>.
    -   After reviewer approval, push to the wp.org SVN `trunk/` + tag `tags/1.0.0/`.
    -   **AC**: Plugin page live at `https://wordpress.org/plugins/logscope/`; "Install Now" works on a clean WP site.

-   [ ] **12.8** Post-release note at top of this file: `> v1.0.0 shipped to wp.org on YYYY-MM-DD.` Close out v1.0 open issues on GitHub.

---

## Post-1.0 — keep-adding cadence

Each version below is a single coherent release. Flow for every one:

1. Branch → implement → PR → merge to `main`.
2. Run `security-review` skill if the release touches REST, file I/O, or external HTTP.
3. Bump version, update `CHANGELOG.md`, tag, push.
4. Push to wp.org SVN (`trunk/` + `tags/X.Y.Z/`), update `Stable tag:` in `trunk/readme.txt`.

### v1.1.0 — Alerts (old Phase 9)

-   [ ] **1.1-a** `src/Alerts/AlertDispatcherInterface.php` + `AlertDeduplicator.php`

    -   Dedup: transient keyed by signature hash + dispatcher name; TTL = configured window.
    -   **Commit**: `feat(alerts): dispatcher interface and dedup`

-   [ ] **1.1-b** `src/Alerts/EmailAlerter.php`

    -   Uses `wp_mail()`. HTML + plaintext fallback.
    -   Subject: `[Logscope] <severity> on <site_name>: <short_msg>`.
    -   **Commit**: `feat(alerts): email dispatcher`

-   [ ] **1.1-c** `src/Alerts/WebhookAlerter.php`

    -   Uses `wp_remote_post()` with a 5s timeout.
    -   Neutral JSON payload: `{site, severity, message, file, line, signature, first_seen, last_seen, count}`.
    -   Filter `logscope/webhook_payload` lets users reshape for Slack/Discord.
    -   **Commit**: `feat(alerts): webhook dispatcher with neutral payload`

-   [ ] **1.1-d** `src/Alerts/AlertCoordinator.php`

    -   Iterates enabled dispatchers, applies dedup per-dispatcher, fires `logscope/before_alert` + `logscope/alert_sent`.
    -   **Commit**: `feat(alerts): coordinator with fanout + dedup`

-   [ ] **1.1-e** `src/REST/AlertsController.php` — `POST /alerts/test`

    -   Sends a test alert to all enabled dispatchers (bypasses dedup).
    -   **Commit**: `feat(alerts): test-alert endpoint`

-   [ ] **1.1-f** Settings UI: alert recipients, webhook URL, dedup window, "Send test alert" button

    -   Extends `SettingsSchema` with alert fields.
    -   **Commit**: `feat(ui): alert settings surface`

-   [ ] **1.1-g** 🔒 `security-review` skill pass on webhook handling + new REST route.

-   [ ] **1.1-h** 🏷️ **Release v1.1.0** — Alerts
    -   Update readme: bump `Stable tag:`, `Tested up to:`, add Alerts screenshot, update Changelog + FAQ.
    -   **Commit**: `chore(release): v1.1.0`
    -   Push to wp.org SVN.

### v1.2.0 — Scheduled fatal scanner (old Phase 10)

-   [ ] **1.2-a** `src/Cron/LogScanner.php`

    -   Registered event `logscope_scan_fatals`, default interval 5 min (filterable).
    -   Reads log since `last_scanned_byte` option, extracts fatals, feeds to `AlertCoordinator`.
    -   **Commit**: `feat(cron): scheduled fatal-error scanner`

-   [ ] **1.2-b** Settings UI: enable/disable + interval override

    -   **Commit**: `feat(ui): cron scanner settings`

-   [ ] **1.2-c** Readme: FAQ entry on WP-cron reliability (spawn via real cron for busy sites).

-   [ ] **1.2-d** 🏷️ **Release v1.2.0** — Scheduled fatal scanner
    -   **Commit**: `chore(release): v1.2.0`
    -   Push to wp.org SVN.

### v1.3.0 — Live streaming

-   SSE or WebSocket replacement for tail-mode polling. Feature-flag in settings, polling stays as fallback. Ship only after measuring on a real WP host — some shared hosts kill long-running PHP.

### v1.4.0 — Multisite aggregation

-   Network admin screen, per-site switch, network-wide capability mapping. Revisit `PathGuard` allowlist semantics for network content dirs.

### v1.5.0+ — Candidates (un-ordered, pick by demand)

-   Log retention / rotation management
-   Export / import of filter presets
-   First-class Slack & Discord formatters (bundled, not filter-based)
-   Opt-in integrations with Loki / Elastic (external HTTP, gated behind explicit user config)

---

## Tracking tips

-   **One commit per checked box** is the ideal. If a step grows beyond that, split the step.
-   Update `CHANGELOG.md` `[Unreleased]` as you go — don't leave it for release day. The version-bump steps are almost free when `[Unreleased]` is already filled in.
-   When a phase completes, write a brief note here: `> Phase N complete on YYYY-MM-DD` — motivating and useful later.
-   **Pre-1.0 tags are git-only.** Do not push any tag to wp.org SVN until step 12.7. After that, every `v1.x.y` tag gets an SVN push.
-   If a step unblocks rethinking an earlier decision, edit this file. **This roadmap is living.**
