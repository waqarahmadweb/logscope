# Logscope

> **Status:** `v0.13.0` — Stats dashboard. New **Stats** tab between Logs and Settings: time-bucketed per-severity counts across a `24h` / `7d` / `30d` window (hour or day buckets), a horizontal stacked breakdown bar showing the severity mix over the range, a small-multiple sparkline grid (hand-rolled SVG, no chart library) tracking each severity's movement over time, and a top-10 signatures table with a "View in Logs" action that pre-populates the FilterBar with the row's severity + a regex on the message prefix. Server-side, `LogStats` reuses the parser + grouper over the same trailing 50 MiB read budget and caches results in a transient keyed by `(size, mtime, range, bucket, snapped-now)` with a 60s TTL — mtime change implicitly invalidates, and the snapped-now anchor means a roll into the next bucket invalidates without requiring a write to the file. Mute filtering is intentionally **not** applied to stats — they are the ground truth of error volume; muting is a Logs-view noise control. Next up: Phase 16 — onboarding, diagnostics, bulk actions.

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
