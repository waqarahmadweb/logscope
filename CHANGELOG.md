# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Composer tooling: `composer.json` with PSR-4 autoload (`Logscope\` → `src/`), WordPress Coding Standards (phpcs + wpcs + PHPCompatibilityWP) and PHPUnit ^9 as dev dependencies, and `lint` / `lint:fix` / `test` scripts. Platform pinned to PHP 8.0 to match plugin minimum.
- PHPCS ruleset `phpcs.xml.dist`: WordPress-Core / -Docs / -Extra + PHPCompatibilityWP, `testVersion` 8.0-, `minimum_supported_wp_version` 6.2, text domain `logscope`. Excludes `vendor/`, `node_modules/`, `assets/build/`, `languages/`. `WordPress.Files.FileName` sniff disabled to allow PSR-4 file naming.

## [0.1.0] - 2026-04-20

### Added

- Initial project scaffold: folder structure matching the target architecture, plugin header file, GPL v2 license, AI agent rules (`AGENTS.md`, `CLAUDE.md`), EditorConfig, `.gitignore`, and `.gitattributes` with wp.org release-export hygiene.
- No runtime behavior yet — plugin activates cleanly in WordPress 6.2+ on PHP 8.0+ and does nothing.

[Unreleased]: https://example.com/logscope/compare/v0.1.0...HEAD
[0.1.0]: https://example.com/logscope/releases/tag/v0.1.0
