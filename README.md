# Logscope

> **Status:** `v0.17.0` — Pre-1.0 feature parity (Phase 19). Closes the three pre-1.0 feature-parity gaps surfaced in the [competitor analysis](.vscode/02-feature-gap-analysis.md): an admin-bar status indicator with a green-or-grey dot reflecting `WP_DEBUG_LOG` and a red badge for today's entry count (gated by a new `admin_bar_enabled` Display setting, default on); a "Logscope · Recent errors" Dashboard widget surfacing the five latest entries with severity pills and relative times; and a Site Health test under Tools → Site Health that goes red on fatals in the last 24 hours, amber on warnings only, green when clean. Each surface deep-links into the Logs tab with relevant filters pre-selected. The fourth feature-parity gap — one-click `WP_DEBUG` toggle that edits `wp-config.php` — is deferred to v1.1.0 / Phase 22.1 so it gets its own security-review pass on the post-1.0 release line. Next up: Phase 20 security gate and the v1.0.0 cut.

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
