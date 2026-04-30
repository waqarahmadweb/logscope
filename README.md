# Logscope

> **Status:** `v0.15.0` — Pre-1.0 release infrastructure (Phase 17.1–17.4). No runtime changes from 0.14.0. This release lands the `readme.txt` the WordPress.org plugin directory will render (mirrors README.md's feature list, condenses CHANGELOG.md 0.1.0 → 0.14.0 into wp.org-flavored bullets, names the only outbound paths under Privacy), the `.wordpress-org/` asset spec the maintainer will fill in with banner / icon / screenshot binaries before the public submission, and a GitHub release workflow that runs on every `v*.*.*` tag push (composer install --no-dev + pnpm build, then assembles a `logscope-<version>.zip` rooted at `logscope/`, with a verify step that fails the build if any of 26 forbidden paths appear or any of seven required paths is missing). The base file set in the zip comes from `git archive HEAD` so `.gitattributes export-ignore` is the source of truth for what stays out; `vendor/` (prod-only) and `assets/build/` are layered in from the workspace since both are gitignored. Next up: Phase 17.5 pre-1.0 changes (TBD) and the v1.0.0 cut.

A free, GPL v2 WordPress plugin that streams, filters, and groups the WordPress debug log from inside wp-admin.

## Highlights (planned)

-   Virtualized viewer for `debug.log` — handles 10k+ lines.
-   Tail mode, severity / regex / date-range filters.
-   Error grouping by signature (file:line + message shape).
-   Stack trace parsing with copy-to-clipboard.
-   Email + generic-webhook alerts for new fatals, rate-limited.
-   REST-first architecture (`/wp-json/logscope/v1/*`), React admin UI.
-   **Free forever.** No paid tier, no telemetry, no upsells.

## For contributors & AI agents

-   **Read [AGENTS.md](AGENTS.md) first.** Primary source of truth for conventions, naming, security rules, and workflow.
-   [CLAUDE.md](CLAUDE.md) — Claude Code-specific operational notes layered on top of `AGENTS.md`.
-   [ROADMAP.md](ROADMAP.md) — phased, versioned plan from scaffold → v1.0.0 (wp.org) → v1.1+ updates.
-   [CHANGELOG.md](CHANGELOG.md) — versioned history.
-   [docs/spec.md](docs/spec.md) — long-form technical specification.

## Requirements

-   PHP **8.0+**
-   WordPress **6.2+**

## License

GPL v2 or later — see [LICENSE](LICENSE).
