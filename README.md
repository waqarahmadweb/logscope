# Logscope

> **Status:** `v0.9.0` — Polish, accessibility, dark mode, and i18n. The admin page picks up its first dedicated stylesheet (variable-driven for light, OS-dark via `prefers-color-scheme`, and the WordPress `admin-color-midnight` admin scheme), loading skeletons replace the bare spinner, REST failures and save successes surface as `@wordpress/components` Snackbars, four global keyboard shortcuts (`/` focus search, `g` grouped, `t` tail, `?` help) land alongside arrow-key tablist navigation, and `languages/logscope.pot` ships covering all 87 PHP and JS strings. Next up: Phase 12 — Alerts (email + webhook + dedup), the first of five feature phases (12–16) added to round the plugin out before the wp.org cut (now Phase 17).

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
