# Logscope

> **Status:** `v0.10.0` — Alerts. Email + webhook dispatchers fan a single fatal out to every enabled backend with per-dispatcher signature dedup so a recurring error does not flood the inbox or webhook endpoint. Email sends as HTML with a plaintext alternative attached via `phpmailer_init`; CRLF in the recipient or subject is refused as defense-in-depth against header injection. Webhook posts a neutral `{site, severity, message, file, line, signature, count, …}` JSON payload reshape-able via the `logscope/webhook_payload` filter for Slack / Discord / Teams; outbound HTTP is hardened with a two-layer http(s) scheme allowlist, `redirection: 0`, and a 5s timeout. A `POST /alerts/test` endpoint backs the Settings UI's "Send test alert" button and bypasses dedup so two consecutive clicks both fire. Next up: Phase 13 — scheduled fatal scanner (cron) wiring the alert pipeline into background WP-cron.

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
