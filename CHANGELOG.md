# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-03

First public release.

### Added

- `jxt sync`: deploy a module or template to a local Joomla test site, based on
  the manifest, including deletion of files that no longer belong to the
  extension, `--dry-run`, and warnings for changes that need the Joomla
  installer.
- `jxt build`: build an installable ZIP file from the manifest.
- `jxt config`: show and change the global and per-project configuration.

[0.1.0]: https://github.com/smarta-lab/jxt/releases/tag/v0.1.0
