# Logscope

> **Status:** `v0.12.0` — Retention, mute, filter presets. Three features bundled into one release. **Retention** is opt-in (`retention_enabled` defaults off): a daily cron renames `debug.log` to `debug.log.archived-YYYYMMDD-HHMMSS` once the file size crosses a configurable threshold (default 50 MB) and prunes the oldest archives beyond a retention cap (default 5). **Mute** is on by default for the Logs view: a "Mute" button on each grouped row records a `{signature, reason, muted_at, muted_by}` entry in `logscope_muted_signatures`, and `LogRepository` filters muted entries before pagination so totals match the visible page; a Settings-tab management panel lists every muted signature with an "Unmute" button, and the `?include_muted=true` query string bypasses the filter for tools that want full visibility. **Filter presets** are per-user (`logscope_filter_presets` user meta): the FilterBar grows a "Save preset" / "Load preset" / "Delete preset" surface that round-trips the `{severity, from, to, q, source, viewMode}` shape so a saved "grouped Akismet fatals" preset restores the exact view. Next up: Phase 15 — stats dashboard.

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
