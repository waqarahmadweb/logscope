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

## 11. Resolved design decisions

Decided in the ROADMAP; do not relitigate without cause:

1. **"Clear log"** — **soft-delete** (rename to `debug.log.cleared-YYYYMMDD-HHMMSS`). Requires `?confirm=true`.
2. **Webhook payload shape** — **neutral JSON** (`{site, severity, message, file, line, signature, first_seen, last_seen, count}`). `logscope/webhook_payload` filter lets users reshape for Slack/Discord/Teams.
3. **Tail polling interval** — **user-configurable**, 3s default, 1s min.
4. **Regex filter** — **server-side** with pattern length ≤ 200 chars.

---

## 12. Build order

The authoritative, phased build plan is [ROADMAP.md](ROADMAP.md). Read it. It is structured around **version bumps** at every phase boundary and a single hard **wp.org release line at v1.0.0**.

Summary of the shape — do **not** re-order without updating the roadmap:

| Version | Scope |
|---|---|
| 0.1.0 ✅ | Scaffold (this commit). |
| 0.2.0 → 0.9.0 | Tooling → bootstrap → parsers → REST → settings backend → admin/React shell → filters/grouping/tail → settings UI → polish/a11y/i18n. Pre-1.0 **git tags only.** |
| **1.0.0** | 🚀 **wp.org submission.** Viewer-first scope. Gated by a full `security-review` skill pass. |
| 1.1.0 | Alerts (email + webhook + dedup) — published to wp.org SVN. |
| 1.2.0 | Scheduled fatal-log scanner (cron). |
| 1.3.0+ | Live streaming, multisite, retention, etc. |

**Alerts and cron are post-1.0, not pre-1.0.** Do not implement them before v1.0.0 ships unless the roadmap is explicitly revised first.

---

## 13. Docs you must update with every change

Shipping code without updating the surrounding docs creates drift fast. **Every commit that is worth a checkbox in the roadmap** must update the relevant docs *in the same commit*:

### 13.1 On every feature / fix / refactor commit

- [ ] **[CHANGELOG.md](CHANGELOG.md)** — append a bullet to the `[Unreleased]` section under the correct heading (`### Added` / `### Changed` / `### Fixed` / `### Deprecated` / `### Removed` / `### Security`). One bullet per user-visible change. Not every internal refactor needs a bullet; user-visible behavior, API surface, security posture, and dependency changes do.
- [ ] **[ROADMAP.md](ROADMAP.md)** — tick the checkbox for the step you completed. If the step's scope shifted, edit the step text too.

### 13.2 On step that closes a phase (version-bump step, 🏷️)

- [ ] **[logscope.php](logscope.php)** — bump the `Version:` header.
- [ ] **[CHANGELOG.md](CHANGELOG.md)** — move `[Unreleased]` content to a new `[X.Y.Z] - YYYY-MM-DD` heading; add a fresh empty `[Unreleased]`; update the link references at the bottom of the file.
- [ ] **[README.md](README.md)** — update the `Status:` line if it changed (e.g. 0.1.0 "scaffold" → 0.4.0 "parsers working").
- [ ] Tag the commit `vX.Y.Z`.

### 13.3 On step that lands a user-facing capability (1.0.0 onward)

- [ ] **`readme.txt`** (exists from Phase 12.1 onward) — mirror the `CHANGELOG.md` entry into the wp.org-format `== Changelog ==` section. If WordPress compatibility changed, bump `Tested up to:`. If PHP minimum changed, bump `Requires PHP:`.
- [ ] **`.wordpress-org/screenshot-*.png`** — if the UI changed materially, re-capture the affected screenshot and update the caption in `readme.txt`.

### 13.4 On step that adds, removes, or changes a public extension point

- [ ] **[AGENTS.md](AGENTS.md) §3** — if a new prefix / namespace is introduced, add it to the naming table.
- [ ] **[AGENTS.md](AGENTS.md) §5** — if an interface boundary changes, update the architecture principles.
- [ ] **`readme.txt`** FAQ or a dedicated `docs/hooks.md` — document new actions, filters, or REST routes so third-party integrators can find them.

### 13.5 On step that changes security-sensitive surface

(PathGuard, REST auth, capability checks, webhook handling, uninstall cleanup, external HTTP)

- [ ] Run the **`security-review` skill** before committing. Record findings in the PR description.
- [ ] **[CHANGELOG.md](CHANGELOG.md)** — use the `### Security` heading for any user-visible security-relevant change.

### 13.6 What NOT to update

- Do **not** update `readme.txt` or `.wordpress-org/` assets before Phase 12.1 — they don't exist yet.
- Do **not** copy architecture or naming conventions into README.md or CHANGELOG.md — those belong in AGENTS.md.
- Do **not** log ephemeral work-in-progress notes into any docs file — that belongs in the PR description or commit body.

### Checklist template for agents

Before marking any roadmap step done, an agent should mentally (or literally) run:

```
[ ] Code change committed
[ ] CHANGELOG.md [Unreleased] updated (if user-visible)
[ ] ROADMAP.md checkbox ticked
[ ] Version-bump fields updated (only on 🏷️ steps)
[ ] readme.txt mirrored (only post-Phase 12.1)
[ ] security-review skill invoked (only on 🔒 steps)
```

If any row is "N/A", say so explicitly in the commit body — never silently skip.
