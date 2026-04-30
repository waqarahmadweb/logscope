# Logscope

> **Status:** `v0.14.0` — Onboarding, diagnostics, bulk actions. A first-time admin opening the plugin on a host with `WP_DEBUG_LOG` off now sees a dismissible-per-session banner above the FilterBar with the exact `wp-config.php` lines to add and a link to the WordPress handbook, instead of a silently empty page. The empty-log state was rewritten to surface the underlying reason (file missing, file empty, all entries muted, filters too narrow) using a typed-fields snapshot served by a new `GET /diagnostics` endpoint over `Logscope\Support\DiagnosticsService` — the same payload feeds the banner and the empty state, so the Logs tab fetches it once on mount. The grouped view grew per-row checkboxes, a tri-state Select-all header, and a bulk action bar with "Mute selected" (POSTs each signature to Phase 14's idempotent `/logs/mute` and refetches the page so muted groups disappear immediately) and "Export selected" (CSV blob built client-side from the rows already in the store, with RFC 4180 quoting and a UTF-8 BOM so Excel auto-detects the encoding). Auto-edit of `wp-config.php` is intentionally out of scope here — deferred to post-1.0. Next up: Phase 17 — wp.org release infrastructure (v0.15.0) and the v1.0.0 cut.

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
