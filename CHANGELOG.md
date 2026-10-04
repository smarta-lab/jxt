# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Configuration key `manifest` in `.jxt.json` for extensions in a subdirectory
  of the project or projects with more than one manifest. All paths of the
  manifest are relative to its directory; the error messages for a missing or
  ambiguous manifest point to the new key.
- `vendor/bin/sjxt` as a shorthand for `sudo vendor/bin/jxt`; it also works
  when `vendor/bin` is only in the user's `PATH`, e.g. via direnv.

### Fixed

- The error messages for several manifests in the project directory and for a
  configuration file that is not a JSON object were still in German.

## [0.1.0] - 2026-10-03

First public release.

### Added

- `jxt sync`: deploy a module or template to a local Joomla test site, based on
  the manifest, including deletion of files that no longer belong to the
  extension, `--dry-run`, and warnings for changes that need the Joomla
  installer.
- `jxt build`: build an installable ZIP file from the manifest.
- `jxt config`: show and change the global and per-project configuration.

[Unreleased]: https://github.com/smarta-lab/jxt/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/smarta-lab/jxt/releases/tag/v0.1.0
