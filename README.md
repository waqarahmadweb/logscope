# Logscope

> **Status:** `v0.11.0` — Scheduled fatal scanner. Phase 12's alert pipeline now has a producer: a new `LogScanner` runs on a configurable WP-Cron schedule (off by default — opt-in via the Settings tab), reads the slice of the log appended since the last tick, filters parsed entries to `fatal` and `parse` severities, groups them, and dispatches the resulting groups through the existing `AlertCoordinator`. Rotation is detected by a shrunk file (`size < last_byte`) and resets the cursor to 0; the per-tick timestamp + dispatched count back the new "Last scan: …" status line in the React Settings UI. A new `CronScheduler` is the single owner of the `logscope_scan_fatals` event so the toggle, the activation hook, and a future WP-CLI all converge on the same schedule via a single `apply()` entry point. Next up: Phase 14 — retention, mute, and filter presets.

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
