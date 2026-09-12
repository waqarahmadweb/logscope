# AGENTS.md

> **Read this file at the start of every session. It is the single source of truth for how to work in this repository.**
> It follows the [AGENTS.md](https://agents.md/) open standard and is read by Claude Code, Cursor, GitHub Copilot, Windsurf, Aider, and other agentic coding tools. `CLAUDE.md` only imports this file.

---

## 1. Project

|                 |                                                                                          |
| --------------- | ---------------------------------------------------------------------------------------- |
| Name            | **Logscope: Debug Log Viewer for WordPress**                                             |
| Slug            | `logscope`                                                                               |
| Type            | WordPress plugin (single-site)                                                           |
| Current version | `1.0.0` tagged; wp.org submission (Phase 21) still open, fixes land under `[Unreleased]` |
| License         | **GPL v2 or later** ([LICENSE](LICENSE)). **Non-negotiable** (wp.org requirement)        |
| Distribution    | **Free forever.** No paid tier. No upsells. No telemetry. No phone-home.                 |
| Repo root       | This directory is the plugin folder AND the git root.                                    |

Tagline: _Stream, filter, and group your WordPress debug log without leaving wp-admin._

Related docs: [ROADMAP.md](ROADMAP.md) (plan) · [CHANGELOG.md](CHANGELOG.md) (what shipped) · [docs/spec.md](docs/spec.md) (technical spec) · [README.md](README.md) (GitHub landing) · `readme.txt` (wp.org listing).

---

## 2. Session working rules (override default agent behaviour)

These exist because the maintainer wants control over pacing, git writes, and branch hygiene. A pause to confirm is cheap; an unwanted commit, a batched step, or a force-push accident is expensive to unwind.

### 2.1 One roadmap step at a time

Do not batch steps. After each step, update the docs listed in §14 before starting the next.

### 2.2 Discuss before acting

Before starting a step, surface: proposed approach, deviations from the roadmap, open questions, trade-offs. Get **explicit approval** before running any tool that writes files, installs dependencies, runs a build, or executes git.

Read-only tools (Read, Grep, Glob, version checks) need no approval. Writes, installers, builds, and `git` do.

### 2.3 Git: suggest, do not execute

The agent drafts commit messages and lists follow-up actions. The **maintainer runs** `git add`, `git commit`, `git push`, `git tag`, `git checkout -b`, `git merge`, unless they explicitly authorise the agent for a specific action in the current conversation ("create the branch", "commit it"). Never stage or commit on your own initiative.

### 2.4 Branch per roadmap step

Every roadmap step gets its own branch, prefixed with the step number:

| Type      | Pattern                        | Example                       |
| --------- | ------------------------------ | ----------------------------- |
| Feature   | `feat/<step>-<short-name>`     | `feat/22.1-wp-debug-toggle`   |
| Fix       | `fix/<step>-<short-name>`      | `fix/22.2-slack-payload`      |
| Tooling   | `chore/<step>-<short-name>`    | `chore/1.5-husky-lint-staged` |
| Refactor  | `refactor/<step>-<short-name>` | `refactor/7.2-log-query`      |
| Docs only | `docs/<step>-<short-name>`     | `docs/21.6-hooks-reference`   |

Work that is not tied to a roadmap step drops the step number (`docs/agents-consolidation`).

Flow:

1. Agent suggests a branch name; maintainer creates it and confirms it is checked out.
2. **Agent waits for that confirmation before any file write, install, or build.** Read-only exploration on `main` is fine. Do not rely on "uncommitted changes carry over" instead of branching first.
3. Agent does the step work on the branch.
4. Agent drafts the commit message; maintainer commits, pushes, opens the PR.
5. Maintainer merges (squash preferred) once CI is green.

No direct commits to `main` for roadmap-step work.

---

## 3. Tech stack

-   **PHP 8.0+**: constructor property promotion, union types, nullsafe, match, named args are all fair game. Do not use PHP 8.1+ features (enums, readonly, never).
-   **WordPress 6.2+** (tested up to 7.0).
-   **Composer** with PSR-4 autoloading: namespace `Logscope\` mapped to `src/`.
-   **React** via **`@wordpress/scripts`**. No custom webpack. No Vite.
-   **`@wordpress/components`** for UI primitives. WP-native look is a feature, not a compromise.
-   **`@wordpress/data`** for cross-component state.
-   **WP REST API** for all PHP to React traffic. No `admin-ajax.php`. No `<form>` POSTs.
-   **pnpm** for all JS dependency operations. **Never `npm` or `yarn`.**
-   **PHPUnit 9** with hand-rolled WP stubs (`tests/php/Stubs/`). No WP test suite, no database.

---

## 4. Naming conventions (HARD: every prefix is load-bearing)

| Thing                                       | Prefix / name                                                         |
| ------------------------------------------- | --------------------------------------------------------------------- |
| PHP namespace                               | `Logscope\`                                                           |
| Hook prefix (actions + filters)             | `logscope/`, e.g. `logscope/before_alert`, `logscope/webhook_payload` |
| Options, transients, cron events, user meta | `logscope_`                                                           |
| REST namespace                              | `logscope/v1`                                                         |
| REST error codes                            | `logscope_rest_<reason>`                                              |
| Custom capability                           | `logscope_manage` (default-maps to `manage_options`)                  |
| Text domain                                 | `logscope`                                                            |
| CSS custom properties / classes             | `--logscope-*` / `.logscope-*`                                        |

**Never** ship code that violates these prefixes. It breaks extensibility contracts and pollutes the WP global namespace.

---

## 5. Code style

### PHP

-   `declare(strict_types=1);` at the top of every file, plus an `ABSPATH` direct-access guard.
-   **WordPress Coding Standards** (PHPCS): tabs for indent, Yoda conditions, etc. `composer lint` must exit 0.
-   Return early; avoid deep nesting.
-   Keep parsers pure so they are trivial to unit-test.
-   Wrap every user-facing string with `__()` / `esc_html__()` / `esc_attr__()` etc., always with text domain `logscope`.
-   Escape on output. Sanitize on input. No exceptions.
-   A `phpcs:ignore` needs a justification on the same line. Never blanket-disable a sniff for a whole file.

### JavaScript / React

-   **Prettier** + **`@wordpress/prettier-config`**. **ESLint** via **`@wordpress/eslint-plugin`**.
-   Small, composable components, one folder per component under `assets/src/components/`.
-   Use `@wordpress/data` (`assets/src/store/`) for anything touched by more than one component.
-   Styles live in `assets/src/style.scss` using the `--logscope-*` tokens. Light theme only for now (see §11).

### Comments

Comment the _why_, not the _what_: business rules, assumptions, edge cases, non-obvious decisions. One tight line by default. No people's names in code or commits. No bare `// TODO`; either do it now or reference the roadmap step (`// TODO 22.1: ...`).

### Commits

-   **Conventional Commits**: `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`, `perf:`, `build:`, `ci:`. Optional scope in parentheses (`fix(a11y): ...`).
-   Imperative mood, no trailing period on the subject line.
-   Reference the roadmap step or spec section in the body if non-obvious.
-   No `Co-Authored-By` or other trailers unless asked.

---

## 6. Architecture principles

1. **Thin main file.** `logscope.php` holds only the plugin header, constants, the autoload include (with a graceful admin notice when `vendor/` is missing), and one `Plugin::boot()` call. All logic lives under `src/`.
2. **Dependency injection over globals.** Lightweight, hand-rolled container in `src/Plugin.php`. No third-party DI package.
3. **Interface boundaries** for replaceable parts: `LogSourceInterface`, `AlertDispatcherInterface`. Readers and alerters can be swapped via filters.
4. **REST-first.** All React to PHP traffic via `/wp-json/logscope/v1/*`.
5. **File-based logs stay file-based.** Never copy log lines into the database. Read, parse, and serve on demand. Only settings, mute list, presets, cron cursors, and alert-dedup state live in `wp_options` / transients / user meta.
6. **Extensibility via hooks.** Every meaningful operation fires a filter or action under the `logscope/` prefix.
7. **Bounded reads.** Every log read is capped (`MAX_BYTES_PER_QUERY`, tail cursors, regex pattern length) so a huge log cannot OOM a request or a cron tick.

---

## 7. Security (HARD)

Non-negotiable. Violating any of these is a bug.

-   **Capability check on every REST route**: `current_user_can( 'logscope_manage' )` via `RestController`.
-   **Full-admin gate on sensitive settings**: `log_path`, `alert_webhook_url`, `alert_email_to` and the clear-log route additionally require `manage_options`. The grantable `logscope_manage` cap must never be able to repoint the log or redirect alerts.
-   **Nonces via WP REST auth.** Never disable.
-   **Path traversal prevention** via `PathGuard`. Custom log paths MUST resolve inside an allowlisted set of directories (WP root, `wp-content`, `ABSPATH`), have a `*.log` or `debug.log*` basename, and reject symlinks that escape. Reject `..` explicitly _before_ `realpath`. Test with adversarial inputs.
-   **Anti-SSRF webhooks.** Outbound alert HTTP goes through `wp_safe_remote_post()` so private, loopback, and internal addresses are refused.
-   **Escape on output, sanitize on input.** React handles most escaping; PHP-side REST responses still sanitize anything read from disk.
-   **Bounded user input.** Regex patterns ≤ 200 chars, mute and preset lists capped, severity filters intersected with `Severity::all()`.
-   **Rate-limit alerts.** Per-dispatcher dedup window (default 5 min) via transients. Prevents alert storms.
-   **Unguessable archive names.** Cleared and rotated logs get a random token suffix so they cannot be enumerated over the web.
-   **No external HTTP** except user-configured alert webhooks. No telemetry. No update checks outside wp.org. No remote fonts, icons, or CSS.
-   **Uninstall cleanup.** `uninstall.php` removes all `logscope_*` options, transients, user meta, and cron events.

---

## 8. Never do

-   **Never** add a paid tier, license gate, "Pro" code path, or upsell UI. Always free.
-   **Never** add telemetry, analytics, or phone-home code.
-   **Never** use jQuery or render admin UI from PHP templates. React only. One accepted exception: `AdminBar`, `DashboardWidget`, and `SiteHealthTest` echo small HTML fragments from PHP because those WordPress surfaces cannot host a React mount. Keep them tiny and keep their severity labels in step with `Severity` and `utils/severity.js`.
-   **Never** bundle minified dependencies into the repo (wp.org will reject it).
-   **Never** read or write files outside the allowlisted log directories.
-   **Never** create a database table for log entries.
-   **Never** add multisite-specific code before the multisite phase (22.4).
-   **Never** load fonts, icons, or CSS from external CDNs.
-   **Never** run `pnpm install`, `pnpm build`, `composer install`, or any installer without asking first.
-   **Never** read or print secrets (`.env*`, `wp-config.php` credentials, tokens). Refer to them by name.

---

## 9. Workflow expectations for AI agents

Before making edits:

1. Read this file.
2. Scan existing configs before creating new ones: `composer.json`, `package.json`, `phpcs.xml.dist`, `eslint.config.mjs`, `.editorconfig`, `.prettierrc`.
3. Match existing patterns. Do not refactor unrelated code. Smallest diff that solves the task; list adjacent problems at the end instead of fixing them.

While working:

-   **pnpm only** for JS deps. Never `npm` or `yarn`.
-   **Never add a dependency without asking first.**
-   Prefer editing existing files over creating new ones. No planning or scratch files inside the repo.
-   When two readings of a request exist, ask one short question before starting.

Before claiming a task is done:

-   `composer lint` passes (`composer lint:fix` to auto-fix).
-   `composer test` passes (PHPUnit, currently ~350 tests).
-   `pnpm lint:js` passes.
-   New parsers, guards, REST routes, or dedup logic have tests.
-   Docs in §14 are updated.

Git hygiene:

-   Conventional Commits (§5).
-   Never skip pre-commit hooks (`--no-verify`) unless explicitly asked.
-   Never run destructive git (`reset --hard`, `push --force`, branch deletion) without confirmation.
-   Stage specific paths only. Never `git add .` or `git add -A`.
-   Create new commits; never amend a published commit.

---

## 10. Claude Code notes

`CLAUDE.md` imports this file and adds nothing else.

### Skills to invoke

-   **`planning-with-files`** for any task expected to need more than ~5 tool calls.
-   **`simplify`** to review changes for reuse and quality before handing back.
-   **`code-review`** or **`review-local`** to review a branch before the PR is opened.
-   **`security-review`**: **always before any release**, and before any PR that touches REST routes, `PathGuard`, settings, alerts, or uninstall.
-   **`cm`** to draft the branch name and commit message (never stages, never commits).

### Subagents

-   **`Explore`** for open-ended codebase research ("find / understand X in this repo"). Prefer it over `general-purpose`.
-   **`Plan`** for design work touching PHP + REST + React together.
-   **`general-purpose`** only for research beyond this repo (docs, external APIs).

### Plan mode

Enter plan mode for: any feature touching PHP + REST + React together; any change to `PathGuard`, alert dispatch, or REST auth; any refactor touching more than 3 files; any task with more than ~5 expected tool calls.

### Memory

Persist: maintainer preferences (commit style, review bar, testing philosophy), validated approaches, non-obvious project facts (deadlines, constraints).
Do not persist: code patterns, file paths, naming conventions (all in this file), or in-progress task state.

### Reviews only when asked

Do not run review agents or audit skills on your own. Finish the code, run lint and tests, hand it back. Reviews are the maintainer's call (except the mandatory `security-review` gate in §14.5).

---

## 11. Resolved design decisions

Decided; do not relitigate without a concrete reason.

1. **Clear log** soft-deletes: rename to `debug.log.cleared-<timestamp>-<random>`. Requires `?confirm=true` and `manage_options`.
2. **Webhook payload shape** is neutral JSON (`{site, severity, message, file, line, signature, first_seen, last_seen, count}`). The `logscope/webhook_payload` filter lets users reshape it. Slack/Discord formatters are a post-1.0 feature (22.2), not a payload change.
3. **Live mode polling interval** is user-configurable: 3s default, 1s minimum. Replaced by SSE/WebSocket only in 22.3.
4. **Regex filter** runs server-side with pattern length ≤ 200 chars.
5. **Light theme only** for now. WordPress 7.0 honours OS dark mode inside wp-admin, which exposed a half-built dark palette; it was removed rather than shipped broken. A proper dark mode is a post-1.0 item.
6. **No `WP_DEBUG` toggle in 1.0.** Editing `wp-config.php` needs its own security review; it ships in 22.1.

---

## 12. Key directories

| Path                                       | Role                                                                                             |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------ |
| `logscope.php`                             | Plugin entry: header, constants, autoload guard, `Plugin::boot()`.                               |
| `uninstall.php`                            | Removes all `logscope_*` state on delete.                                                        |
| `src/Plugin.php`                           | Orchestrator, service wiring, hook registration.                                                 |
| `src/Activator.php`, `src/Deactivator.php` | Capability grant, cron (un)scheduling, option defaults.                                          |
| `src/Admin/`                               | Menu, asset loader, page renderer, admin bar, dashboard widget, Site Health test.                |
| `src/Log/`                                 | File source, parser, stack-trace parser, grouper, query, repository, stats, rotator, mute store. |
| `src/REST/`                                | `RestController` base + Logs, Settings, Alerts, Mute, Presets, Stats, Diagnostics controllers.   |
| `src/Settings/`                            | Settings storage, schema (single source of truth for shape), preset store.                       |
| `src/Alerts/`                              | Coordinator, email + webhook dispatchers, deduplicator.                                          |
| `src/Cron/`                                | Scheduler + scanner for new fatals.                                                              |
| `src/Support/`                             | `Capabilities`, `PathGuard`, `DiagnosticsService`, path exceptions.                              |
| `assets/src/`                              | React source: `components/`, `hooks/`, `store/`, `api/`, `utils/`, `style.scss`.                 |
| `assets/build/`                            | `@wordpress/scripts` output. **Gitignored.**                                                     |
| `languages/`                               | `logscope.pot` (regenerate with `pnpm i18n`). `.mo` files gitignored.                            |
| `tests/php/Unit/`                          | PHPUnit unit tests, mirrors `src/`.                                                              |
| `tests/php/Integration/`                   | PHPUnit tests for REST controllers, repository, cron scanner.                                    |
| `tests/php/Stubs/`                         | Minimal WP class stubs so tests run without WordPress.                                           |
| `tests/js/`                                | Empty placeholder. No JS tests yet.                                                              |
| `bin/`                                     | `build-zip.ps1` (local zip), `make-pot.mjs` (downloads wp-cli into `tools/`, gitignored).        |
| `docs/`                                    | `spec.md` only. Working reports and mockups live outside the repo.                               |
| `.wordpress-org/`                          | wp.org listing assets (banner, icon, screenshots). Export-ignored from the zip.                  |
| `.github/workflows/`                       | `ci.yml` (lint, build, audit, Plugin Check), `release.yml` (zip on `v*` tag).                    |
| `readme.txt`, `changelog.txt`              | wp.org listing + full version history. Both ship in the zip.                                     |

Public surface (keep in sync with `readme.txt` FAQ when it changes):

-   REST: `GET /logs`, `DELETE /logs`, `GET /logs/download`, `GET|POST /logs/mute`, `DELETE /logs/mute/{signature}`, `GET|POST /settings`, `POST /settings/test-path`, `POST /alerts/test`, `GET|POST /presets`, `DELETE /presets/{name}`, `GET /stats`, `GET /diagnostics`.
-   Filters: `logscope/required_capability`, `logscope/before_alert`, `logscope/email_subject`, `logscope/webhook_payload`.
-   Actions: `logscope/booted`, `logscope/alert_sent`.

---

## 13. Build order

The authoritative, phased plan is [ROADMAP.md](ROADMAP.md). It is structured around a version bump at every phase boundary; the wp.org release line starts at **v1.0.0**.

| Version        | Phase   | Scope                                                                                                          | Status                    |
| -------------- | ------- | -------------------------------------------------------------------------------------------------------------- | ------------------------- |
| 0.1.0          | 0       | Scaffold                                                                                                       | shipped                   |
| 0.2.0 to 0.9.0 | 1 to 11 | Tooling, bootstrap, parsers, REST, settings, React shell, filters/grouping/tail, settings UI, polish/a11y/i18n | shipped                   |
| 0.10.0         | 12      | Alerts (email + webhook + dedup)                                                                               | shipped                   |
| 0.11.0         | 13      | Scheduled fatal scanner (cron)                                                                                 | shipped                   |
| 0.12.0         | 14      | Retention, mute, filter presets                                                                                | shipped                   |
| 0.13.0         | 15      | Stats dashboard                                                                                                | shipped                   |
| 0.14.0         | 16      | Onboarding, diagnostics, bulk actions                                                                          | shipped                   |
| 0.15.0         | 17      | wp.org release infrastructure                                                                                  | shipped                   |
| 0.16.0         | 18      | UI redesign (soft-pastel, Linear density)                                                                      | shipped                   |
| 0.17.0         | 19      | Admin bar, dashboard widget, Site Health                                                                       | shipped                   |
| 0.18.0         | 20      | Security gate                                                                                                  | shipped                   |
| **1.0.0**      | 21      | **wp.org submission**                                                                                          | tagged, not yet submitted |
| 1.1.0          | 22.1    | One-click `WP_DEBUG` toggle (edits wp-config.php)                                                              | after 1.0 ships           |
| 1.2.0          | 22.2    | Slack and Discord webhook formatters                                                                           |                           |
| 1.3.0          | 22.3    | Live streaming (SSE / WebSocket)                                                                               |                           |
| 1.4.0          | 22.4    | Multisite aggregation                                                                                          |                           |
| 1.5.0+         | 22.5    | Source preview, WP-CLI, request context, Loki/Elastic, etc.                                                    |                           |

Do not re-order without updating the roadmap. From 1.0.0 on, every `vX.Y.Z` tag is a public release: the tag triggers the zip build, and the maintainer pushes to wp.org SVN.

---

## 14. Docs you must update with every change

Shipping code without updating the surrounding docs creates drift fast. **Every commit worth a roadmap checkbox** updates the relevant docs _in the same commit_.

### 14.1 Every feature / fix / refactor commit

-   [ ] **[CHANGELOG.md](CHANGELOG.md)**: add a bullet under `[Unreleased]` with the right heading (`### Added` / `### Changed` / `### Fixed` / `### Deprecated` / `### Removed` / `### Security`). One bullet per user-visible change. Purely internal refactors need no bullet; say so in the commit body.
-   [ ] **[ROADMAP.md](ROADMAP.md)**: tick the checkbox for the step. If the step's scope shifted, edit the step text too.

### 14.2 Version-bump step (🏷️, closes a phase)

-   [ ] **[logscope.php](logscope.php)**: bump the `Version:` header.
-   [ ] **`readme.txt`**: bump `Stable tag`; bump `Tested up to` / `Requires PHP` if they changed.
-   [ ] **[CHANGELOG.md](CHANGELOG.md)**: move `[Unreleased]` to a `[X.Y.Z] - YYYY-MM-DD` heading; add a fresh empty `[Unreleased]`; update the link references at the bottom.
-   [ ] **`readme.txt`** `== Changelog ==`: add a short wp.org-format entry. Keep only the two most recent releases there.
-   [ ] **`changelog.txt`**: add the full entry (this file holds the complete history).
-   [ ] **[README.md](README.md)**: update the status line or feature list if they changed.
-   [ ] **[docs/spec.md](docs/spec.md)**: update feature scope if a feature moved in or out of the plugin.
-   [ ] Tag the commit `vX.Y.Z` (maintainer does this).

### 14.3 User-facing UI change

-   [ ] **`.wordpress-org/screenshot-*.jpg`**: re-capture affected screenshots and update the caption in `readme.txt` `== Screenshots ==`. Filenames and captions pair by index; keep them in lockstep.

### 14.4 Public extension point added, removed, or changed

-   [ ] **§4** if a new prefix or namespace is introduced.
-   [ ] **§6** if an interface boundary changes.
-   [ ] **§12** public-surface list (routes, filters, actions).
-   [ ] **`readme.txt`** FAQ so integrators can find it.

### 14.5 Security-sensitive surface (🔒)

(`PathGuard`, REST auth, capability checks, settings gates, webhook handling, uninstall cleanup, external HTTP, anything that edits `wp-config.php`)

-   [ ] Run the **`security-review`** skill before the commit. Record findings in the PR description.
-   [ ] **[CHANGELOG.md](CHANGELOG.md)**: use the `### Security` heading.

### 14.6 Do not

-   Do not copy architecture or naming conventions into README.md or CHANGELOG.md. They belong here.
-   Do not log work-in-progress notes into any docs file. That belongs in the PR description or commit body.
-   Do not create reports, audits, or mockups inside the repo. They live in the maintainer's `Data/` folder outside git.

### Checklist template

Before marking any roadmap step done:

```
[ ] Code change committed
[ ] CHANGELOG.md [Unreleased] updated (if user-visible)
[ ] ROADMAP.md checkbox ticked
[ ] Version fields + readme.txt + changelog.txt updated (only on 🏷️ steps)
[ ] Screenshots re-captured (only if UI changed)
[ ] security-review skill invoked (only on 🔒 steps)
```

If any row is "N/A", say so explicitly in the commit body. Never silently skip.
