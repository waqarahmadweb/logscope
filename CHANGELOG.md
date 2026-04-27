# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-04-27

Closes Phase 3 of the [roadmap](ROADMAP.md): the log-reading and parsing foundation. Logscope can now safely locate a `debug.log` (PathGuard allowlist + traversal protection), stream bytes out of it without loading the whole file into memory, parse the WordPress severity formats and PHP stack traces, group "the same error happening over and over" by signature, and serve filtered, paginated views through a single repository facade. Still no user-visible features — REST routes and the React UI come next in Phase 4 onward.

### Added

-   `Logscope\Support\PathGuard` — filesystem path validator with two-stage rejection (raw-string checks for null bytes and `..` segments before any filesystem call, then `realpath()` canonicalisation with allowlist containment). Symlink escapes are caught implicitly because `realpath()` resolves links before the containment check; sibling-prefix attacks (e.g. `/var/www-evil` against root `/var/www`) are rejected by the exact-separator suffix match. Constructor fails closed: a non-existent root is silently dropped so a misconfigured root cannot widen the allowlist. Companion `InvalidPathException` carries plain-English internal messages; translation happens at the REST/UI boundary later.
-   `Logscope\Log\LogSourceInterface` and `FileLogSource` — byte-level abstraction over the underlying log file with `path() / exists() / size() / read_chunk(from, max)`. Reads use `fopen('rb') / fseek / fread` so multi-hundred-MB logs never hit memory all at once. Constructor validates the parent directory through `PathGuard` and reassembles the final path from the validated dirname plus `basename()` of the input — keeping the contract usable on a fresh WordPress install where `debug.log` does not yet exist, while still defeating traversal in the input. Past-EOF, missing-file, and non-positive-bound reads return an empty string rather than throwing.
-   `Logscope\Log\LogParser`, `Entry`, and `Severity` — pure parser turning raw debug-log text into structured `Entry[]`. Recognises all six WordPress severity tokens (`Fatal error`, `Parse error`, `Warning`, `Notice`, `Deprecated`, `Strict Standards`) plus an `unknown` bucket for unsevered `error_log()` output. Parses WP's `[DD-Mon-YYYY HH:MM:SS [TZ]]` timestamp form with or without the timezone token. Continuation lines (stack-trace rows, `thrown in ...` tails, blank separators) attach to the previous entry's `raw` field; orphan continuations at chunk start are dropped because chunk stitching is the future repository's responsibility. File and line are extracted from both `... in /path.php:N` and `... in /path.php on line N` shapes.
-   `Logscope\Log\StackTraceParser` and `Frame` — parses PHP's `#N <file>(<line>): <call>` stack-trace rows into `Frame[]`. Recognises bare-function, instance (`->`), and static (`::`) calls with namespaced class names; special `[internal function]` and `{main}` frames are represented by null patterns rather than dedicated subtypes. The file regex anchors on the rightmost `(\d+):` so Windows-style paths like `C:\Program Files (x86)\app\main.php` parse correctly even with parens earlier in the path. Argument lists are captured as raw strings and never evaluated, since PHP's trace output for arguments routinely contains truncated literals and `Object(...)` placeholders that are not valid source.
-   `Logscope\Log\LogGrouper` and `Group` — collapses `Entry[]` into "the same error happening over and over" buckets. The signature is `md5(severity | file | line | normalised-message)`, where normalisation strips hex addresses (`0x[0-9a-fA-F]+` → `0xN`), single- and double-quoted strings (→ `'?'` / `"?"`), and bare integers (→ `N`). Order matters: hex first so the digit rule does not eat address bytes. Groups carry a running `count`, the first-observed `sample_message` for display, and a `first_seen` / `last_seen` window built from parsed `DateTimeImmutable` comparisons rather than raw string compares (the WP timestamp format is not lexically sortable across months). Output is sorted by descending count, with ties broken by most-recent `last_seen` then signature for stable ordering across runs.
-   `Logscope\Log\LogRepository`, `LogQuery`, `PagedResult`, `SourceClassifier`, and `LogQueryException` — facade over the source / parser / grouper stack with paginated, filtered access. `LogQuery` validates severity, date window (`Y-m-d` or `Y-m-d H:i:s`), regex (≤ 200 chars, server-side, message-scoped), source slug (`plugins/<slug>` / `themes/<slug>` / `mu-plugins/<slug>` / `core` produced by `SourceClassifier`), grouped flag, and pagination at construction; downstream code can trust every field. `LogRepository::query()` returns a `PagedResult` with `items`, `total`, `page`, `per_page`, `total_pages` ready for `X-WP-Total` / `X-WP-TotalPages` headers. Ungrouped queries return entries newest-first (reverse of file order, since WP appends); grouped queries return `Group[]` in count-desc order. Whole-file reads are bounded by `MAX_BYTES_PER_QUERY = 50 MB` — pathological logs are tail-clipped and the parser drops the orphan continuation at the cutoff. `distinct_sources()` exposes the dropdown population list. New `Integration` PHPUnit suite registered; `tests/php/Integration/Log/LogRepositoryTest.php` covers the AC end-to-end (page 2 of 50 with `severity=Fatal` over a 75-fatal fixture).

### Security

-   PathGuard is the foundation for all log-file access in later phases — every `FileLogSource` and settings-side path test will route through it before touching disk.

## [0.3.0] - 2026-04-23

Closes Phase 2 of the [roadmap](ROADMAP.md): the plugin now has a bootstrap entry point, a lightweight DI container, activation/deactivation/uninstall lifecycle, a reusable capability helper, and the PHPUnit + Brain Monkey scaffolding future tests will build on. Still no user-visible features.

### Added

-   Plugin bootstrap: `src/Plugin.php` with a hand-rolled lazy service container (`register`/`has`/`get`) and a single `Plugin::boot()` entry point. `logscope.php` now defines `LOGSCOPE_PLUGIN_FILE`, requires Composer autoload, and hooks `Plugin::boot` on `plugins_loaded` priority 5. Fires the `logscope/booted` action once the container is built so extensions can register services. Text domain is loaded on `init`.
-   Lifecycle hooks: `Activator` seeds default options (`logscope_log_path`, `logscope_tail_interval`, `logscope_db_version`) and grants the `logscope_manage` capability to administrators. `Deactivator` clears Logscope-owned cron events. `uninstall.php` deletes all `logscope_*` options, transient prefixes, and removes `logscope_manage` from every role — works whether the plugin is active at the time of deletion or not.
-   Unit test scaffolding: `brain/monkey` dev dependency, `phpunit.xml.dist`, `tests/php/bootstrap.php`, and a shared `Logscope\Tests\TestCase` base class wiring Brain Monkey setup/teardown.
-   `Logscope\Support\Capabilities` helper with `required()` and `has_manage_cap(?int $user_id = null)`. The `logscope/required_capability` filter lets site owners remap the required cap; non-string / empty filter returns fall back to the default so authorization cannot be silently disabled.

## [0.2.0] - 2026-04-22

Closes Phase 1 of the [roadmap](ROADMAP.md): composer, pnpm, phpcs, ESLint/Prettier, CI, and pre-commit hooks are all in place. No user-visible plugin behavior yet.

### Added

-   Composer tooling: `composer.json` with PSR-4 autoload (`Logscope\` → `src/`), WordPress Coding Standards (phpcs + wpcs + PHPCompatibilityWP) and PHPUnit ^9 as dev dependencies, and `lint` / `lint:fix` / `test` scripts. Platform pinned to PHP 8.0 to match plugin minimum.
-   PHPCS ruleset `phpcs.xml.dist`: WordPress-Core / -Docs / -Extra + PHPCompatibilityWP, `testVersion` 8.0-, `minimum_supported_wp_version` 6.2, text domain `logscope`. Excludes `vendor/`, `node_modules/`, `assets/build/`, `languages/`. `WordPress.Files.FileName` sniff disabled to allow PSR-4 file naming.
-   JS tooling: `package.json` with `@wordpress/scripts` build pipeline, `@wordpress/components` / `data` / `i18n` / `api-fetch`, React 18, and `@wordpress/eslint-plugin` + `@wordpress/prettier-config`. Scripts: `build`, `start`, `lint:js`, `format`, `packages-update`. Flat ESLint config via `eslint.config.mjs`, Prettier via `.prettierrc` + `.prettierignore`, `.npmrc` with `strict-peer-dependencies=false`. Entry point placeholder at `assets/src/index.js`; build output path `assets/build/` (gitignored). pnpm pinned via `packageManager`, Node ≥20.17 (required by `lint-staged` v16; Node 18 is EOL as of April 2025).
-   GitHub Actions CI workflow `.github/workflows/ci.yml` with four jobs: `php-lint` (matrix PHP 8.0–8.4), `js-lint` (`pnpm lint:js` + `pnpm build`), `audit` (`composer audit` + `pnpm audit --audit-level=high --prod`), and `plugin-check` (wp.org [Plugin Check](https://github.com/WordPress/plugin-check-action), informational until Phase 12.1). Triggers: push to `main`, all pull requests, manual dispatch. Concurrency group cancels in-progress runs on the same ref.
-   Pre-commit hook via [husky](https://typicode.github.io/husky) + [lint-staged](https://github.com/lint-staged/lint-staged): auto-runs `vendor/bin/phpcbf` on staged `*.php` files and `prettier --write --ignore-unknown` on staged `*.{js,jsx,json,md,css,yml,yaml}` files. Installed on `pnpm install` via the `prepare` script. `.husky/` and `.npmrc` added to `.gitattributes export-ignore` so they stay out of the wp.org zip.

## [0.1.0] - 2026-04-20

### Added

-   Initial project scaffold: folder structure matching the target architecture, plugin header file, GPL v2 license, AI agent rules (`AGENTS.md`, `CLAUDE.md`), EditorConfig, `.gitignore`, and `.gitattributes` with wp.org release-export hygiene.
-   No runtime behavior yet — plugin activates cleanly in WordPress 6.2+ on PHP 8.0+ and does nothing.

[Unreleased]: https://github.com/waqarahmadweb/logscope/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/waqarahmadweb/logscope/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/waqarahmadweb/logscope/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/waqarahmadweb/logscope/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/waqarahmadweb/logscope/releases/tag/v0.1.0
