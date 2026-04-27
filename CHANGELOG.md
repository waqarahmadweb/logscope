# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

-   `Logscope\Support\PathGuard` — filesystem path validator with two-stage rejection (raw-string checks for null bytes and `..` segments before any filesystem call, then `realpath()` canonicalisation with allowlist containment). Symlink escapes are caught implicitly because `realpath()` resolves links before the containment check; sibling-prefix attacks (e.g. `/var/www-evil` against root `/var/www`) are rejected by the exact-separator suffix match. Constructor fails closed: a non-existent root is silently dropped so a misconfigured root cannot widen the allowlist. Companion `InvalidPathException` carries plain-English internal messages; translation happens at the REST/UI boundary later.
-   `Logscope\Log\LogSourceInterface` and `FileLogSource` — byte-level abstraction over the underlying log file with `path() / exists() / size() / read_chunk(from, max)`. Reads use `fopen('rb') / fseek / fread` so multi-hundred-MB logs never hit memory all at once. Constructor validates the parent directory through `PathGuard` and reassembles the final path from the validated dirname plus `basename()` of the input — keeping the contract usable on a fresh WordPress install where `debug.log` does not yet exist, while still defeating traversal in the input. Past-EOF, missing-file, and non-positive-bound reads return an empty string rather than throwing.

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

[Unreleased]: https://github.com/waqarahmadweb/logscope/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/waqarahmadweb/logscope/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/waqarahmadweb/logscope/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/waqarahmadweb/logscope/releases/tag/v0.1.0
