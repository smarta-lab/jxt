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
- `jxt install`: builds the ZIP file and installs it with the Joomla CLI
  (`cli/joomla.php extension:install`) as the web server user via
  `sudo -n -u <web_user>`, so no root privileges and no backend are needed.
  If sudo asks for a password, the matching sudoers rule is printed.
- Configuration key `web_user` (default `www-data`); `null` runs the Joomla CLI
  without sudo.

### Changed

- `jxt sync` no longer suggests sudo; its hints for the first installation and
  for installer-relevant changes point to `jxt install`.

### Removed

- Configuration keys `owner` and `group` and the `chown`/`chgrp` of `jxt sync`
  under sudo: running jxt as root executed the whole Composer autoloader of the
  project, including all dev dependencies, as root. Remove both keys from
  existing configuration files, unknown keys are rejected.

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
