# AGENTS.md

> **Read this file at the start of every session. It is the single source of truth for how to work in this repository.**
> This file follows the emerging [AGENTS.md](https://agents.md/) open standard and is read by Claude Code, Cursor, GitHub Copilot, Windsurf, Aider, and other agentic coding tools.

---

## 1. Project

| | |
|---|---|
| Name | **Logscope — Debug Log Viewer for WordPress** |
| Slug | `logscope` |
| Type | WordPress plugin (single-site, v1) |
| Current version | `0.1.0` (scaffold — no features yet) |
| License | **GPL v2 or later** ([LICENSE](LICENSE)) — **non-negotiable** (wp.org requirement) |
| Distribution | **Free forever.** No paid tier. No upsells. No telemetry. No phone-home. |
| Repo root | This directory is the plugin folder AND the git root. |

Tagline: *Stream, filter, and group your WordPress debug log without leaving wp-admin.*

---

## 2. Tech stack

- **PHP 8.0+** — constructor property promotion, union types, nullsafe, match, named args are all fair game. Do not use PHP 8.1+ features (enums, readonly, never) yet.
- **WordPress 6.2+**
- **Composer** with PSR-4 autoloading — namespace `Logscope\` mapped to `src/`. *(Added in commit 2.)*
- **React** via **`@wordpress/scripts`** build toolchain. No custom webpack. No Vite.
- **`@wordpress/components`** for UI primitives. WP-native look is a feature, not a compromise.
- **`@wordpress/data`** for cross-component state.
- **WP REST API** for all PHP ↔ React traffic. No `admin-ajax.php`. No `<form>` POSTs.
- **pnpm** for all JS dependency operations. **Never `npm` or `yarn`.**

---

## 3. Naming conventions (HARD — every prefix is load-bearing)

| Thing | Prefix / name |
|---|---|
| PHP namespace | `Logscope\` |
| Hook prefix (actions + filters) | `logscope/` — e.g. `logscope/log_parsed`, `logscope/before_alert` |
| Options, transients, cron events, user meta | `logscope_` |
| REST namespace | `logscope/v1` |
| Custom capability | `logscope_manage` (default-maps to `manage_options`) |
| Text domain | `logscope` |

**Never** ship code that violates these prefixes — it breaks extensibility contracts and pollutes the WP global namespace.

---

## 4. Code style

### PHP
- `declare(strict_types=1);` at the top of every file.
- **WordPress Coding Standards** (PHPCS) — tabs for indent, Yoda conditions, etc.
- Return early; avoid deep nesting.
- Keep parsers pure — trivial to unit-test.
- Wrap every user-facing string with `__()` / `esc_html__()` / `_e()` / `esc_attr__()` etc., always with text domain `logscope`.
- Escape on output. Sanitize on input. No exceptions.

### JavaScript / React
- **Prettier** + **`@wordpress/prettier-config`**.
- **ESLint** via **`@wordpress/eslint-plugin`**.
- Small, composable components. Colocate styles (SCSS modules per `@wordpress/scripts` conventions).
- Use `@wordpress/data` for anything touched by more than one component.

### Commits
- **Conventional Commits**: `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`, `perf:`, `build:`, `ci:`.
- Imperative mood, no trailing period on the subject line.
- Reference the relevant spec section or issue in the body if non-obvious.

### Branches
- `feat/<short-name>`, `fix/<short-name>`, `chore/<short-name>`, `docs/<short-name>`, `refactor/<short-name>`.

---

## 5. Architecture principles

1. **Thin main file.** `logscope.php` holds only the plugin header, autoload include, and one `Plugin::boot()` call. All logic lives under `src/`.
2. **Dependency injection over globals.** Lightweight, hand-rolled container in `src/Plugin.php` — no third-party DI package.
3. **Interface boundaries** for replaceable parts: `LogSourceInterface`, `AlertDispatcherInterface`. Readers and alerters can be swapped via filters.
4. **REST-first.** All React ↔ PHP traffic via `/wp-json/logscope/v1/*`.
5. **File-based logs stay file-based.** Never copy log lines into the database. Read, parse, and serve on demand. Only settings and alert-dedup state live in `wp_options` / transients.
6. **Extensibility via hooks.** Every meaningful operation fires a filter or action under the `logscope/` prefix.

---

## 6. Security — **HARD**

These are non-negotiable. Violating any of them is a bug.

- **Capability check on every REST route**: `current_user_can( 'logscope_manage' )`.
- **Nonces via WP REST auth** — never disable.
- **Path traversal prevention** — `PathGuard` class. Custom log paths MUST resolve to an allowlisted set of directories (WP root, `wp-content`, `ABSPATH`). Reject symlinks that escape. Reject `..` explicitly *before* calling `realpath`. Test with adversarial inputs.
- **Escape on output, sanitize on input.** React handles most escaping; PHP-side REST responses still sanitize anything read from disk.
- **Rate-limit alerts** — dedup window (default 5 min) via transients. Prevents alert storms.
- **No external HTTP** except user-configured alert webhooks. No telemetry. No update checks outside wp.org. No remote fonts, icons, or CSS.
- **Uninstall cleanup** — `uninstall.php` removes all `logscope_*` options and transients.

---

## 7. Never do

- **Never** add a paid tier, license gate, "Pro" code path, or upsell UI. Always free.
- **Never** add telemetry, analytics, or phone-home code.
- **Never** use jQuery or render admin UI from PHP templates. React only.
- **Never** bundle minified dependencies into the repo (wp.org will reject it).
- **Never** read or write files outside the allowlisted log directories.
- **Never** create a database table for log entries.
- **Never** add multisite-specific code in v1.
- **Never** load fonts, icons, or CSS from external CDNs.

---

## 8. Workflow expectations for AI agents

Before making edits:
1. Read this file (`AGENTS.md`).
2. Scan for existing configs before creating new ones: `composer.json`, `package.json`, `phpcs.xml.dist`, `eslint.config.js`, `.editorconfig`, `.prettierrc`.
3. Match existing patterns. Don't refactor unrelated code.

While working:
- **pnpm only** for JS deps. `pnpm add`, `pnpm install`, `pnpm exec`. Never `npm` or `yarn`.
- **Never install a new dependency without asking first.**
- Prefer editing existing files over creating new ones.
- No comments that explain *what* code does — only *why*, and only when the why is non-obvious.

Before claiming a task is done (once tooling exists — commit 2 onward):
- `composer lint` passes (or `composer lint:fix` to auto-fix).
- `pnpm lint` passes.
- New parsers / guards / dedup logic have unit tests.

Git hygiene:
- Conventional Commits (§4).
- Never skip pre-commit hooks (`--no-verify`) unless the user explicitly asks.
- Never run destructive git (`reset --hard`, `push --force`, branch deletion) without confirmation.
- Add specific file paths to `git add` — never `git add .` or `git add -A`.

---

## 9. Claude Code-specific notes

`CLAUDE.md` delegates to this file and adds only a thin layer of Claude-specific operational notes (skills, subagents, memory). When working in Claude Code:
- Use the **`Explore`** subagent for open-ended codebase research.
- Invoke relevant **skills**: `simplify`, `review`, `security-review`, `commit-commands:commit`, `planning-with-files`.
- Use **plan mode** for any task that touches PHP + REST + React together, or any task with more than ~5 expected tool calls.
- Persist **memory** for user preferences, project facts, and validated approaches. Don't save ephemeral task state.

---

## 10. Key directories

| Path | Role |
|---|---|
| `logscope.php` | Plugin entry — header + `Plugin::boot()` (bootstrap lands in commit 3+). |
| `src/Plugin.php` | Orchestrator, service wiring. |
| `src/Admin/` | wp-admin menu, asset loader, React mount point. |
| `src/Log/` | Log source, parser, grouper, repository. |
| `src/REST/` | REST controllers — base class + endpoints. |
| `src/Settings/` | Settings storage + schema. |
| `src/Alerts/` | Alert coordinator, email + webhook dispatchers, dedup. |
| `src/Cron/` | Scheduled fatal-log scanner. |
| `src/Support/` | `Capabilities`, `PathGuard`, `Sanitizer`. |
| `assets/src/` | React source (app entry, components, hooks, store, API client). |
| `assets/build/` | `@wordpress/scripts` output. **Gitignored.** |
| `languages/` | `logscope.pot` (generated at release). `.mo` files gitignored. |
| `tests/php/Unit/` | PHPUnit — parsers, grouper, path guard, dedup. |
| `tests/php/Integration/` | PHPUnit — REST endpoints. |
| `tests/js/` | Jest — minimal for MVP. |
| `.wordpress-org/` | wp.org listing assets (banner, icon, screenshots). |

---

## 11. Open questions (decide when relevant, don't assume)

Raised in the original spec, still open:

1. **"Clear log"** — soft-delete (rename with timestamp) or hard-delete? *Lean: soft-delete.*
2. **Webhook payload shape** — mimic Slack's `text` field or a neutral shape? *Lean: neutral + doc Slack/Discord mappings.*
3. **Tail polling interval** — fixed or configurable? *Lean: configurable, 3s default, 1s min.*
4. **Regex filter** — server-side (safer, better for large logs) or client-side (snappier)? *Lean: server-side with a pattern-length cap.*

Ask the user before committing to any of these.

---

## 12. Build order (not mandatory, but de-risks the hardest parts first)

1. ✅ Commit 1 — Bare scaffold, AI rules, license (this commit).
2. Commit 2 — `composer.json`, `package.json`, `phpcs.xml.dist`, `.prettierrc`, `eslint.config.js`, lockfiles, tooling scripts.
3. Commit 3 — Plugin bootstrap: `src/Plugin.php`, `Activator`, `Deactivator`, autoload wiring in `logscope.php`.
4. `FileLogSource` + `LogParser` + unit tests.
5. REST: `GET /logs` with pagination and filters.
6. React shell + `LogViewer` consuming the REST endpoint.
7. Filters, grouping, stack trace expansion.
8. Settings page + `SettingsController`.
9. Alerts: coordinator, email, webhook, dedup.
10. Cron scanner for new fatals.
11. Polish: empty states, loading skeletons, keyboard shortcuts, accessibility pass.
12. `readme.txt`, screenshots, wp.org assets.
13. PHPUnit + ESLint in CI.
