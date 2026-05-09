# Logscope

> **Status:** `v0.16.0` — Pre-1.0 UI redesign (Phase 18). Closes the warm-pastel / Linear-density UI redesign tracked under Phase 18 of the roadmap. Six of the seven items shipped earlier under the merged 17.5a / 17.5b branches (design tokens, page header with live counter pill, pill tabs, single-row FilterBar with summary line, log table redesign with inline search highlighting, sticky bulk-action bar with inline list-view bulk mute) and were ticked retroactively; the stack-trace panel restyle (18.7) — four-column grid, plugin / theme / mu-plugin / core color coding via a new client-side `frameSource` classifier — is the only fresh code change in the cut. Beyond the roadmap items this release also rolls in the Display section of the Settings panel, the read-only WordPress debug constants card, and a stack of save / dedup / uninstall fixes that accumulated since 0.15.0. Next up: Phase 19 pre-1.0 feature parity (admin bar indicator, dashboard widget, Site Health test) and the v1.0.0 cut.

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
