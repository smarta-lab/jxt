# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Libraries (`type="library"`): `jxt build`, `jxt install`, `jxt sync` and
  `jxt check` support them, including names with a vendor such as
  `acme/example` (`libraries/acme/example/`) and the manifest in
  `administrator/manifests/libraries/`. Names starting with a directory or
  library of Joomla (`joomla`, `php-encryption`, `phpass`, `src`, `vendor`)
  are rejected. The jxt bridge 0.2.0 accepts the targets of libraries.

## [0.5.0] - 2026-10-23

### Added

- Test sites in containers: `cli_command` runs the Joomla CLI for
  `jxt install`, `jxt bridge` and the bridge in `jxt sync` via a command such
  as `docker exec -u www-data <container> php`, and `cli_root` is the path of
  the site in the container. The ZIP file and the staged plan go to the site's
  `tmp/` directory, which the container can see. Tested with DDEV and the
  official Joomla Docker image.
- `jxt bridge status` shows how jxt runs the Joomla CLI.

### Fixed

- The jxt bridge 0.1.1 no longer makes PHP warn about a `use` statement without
  effect on every run of the Joomla CLI, visible where PHP shows warnings, e.g.
  in the official Joomla Docker image.

## [0.4.1] - 2026-10-23

### Fixed

- `jxt check` ignores language constants in comments of PHP, XML, JS and SQL
  files; commented-out code was reported as using undefined constants and
  counted as using defined ones.
- `jxt check` also looks for language constants in SQL files, e.g. mail
  templates or statuses inserted by the install script, which were reported
  as unused.
- Language keys without the prefix of the extension are searched literally;
  they were always reported as unused.
- `jxt check` compares language files without the language tag in front of
  the file name (`de-DE.plg_system_example.ini`), which Joomla also loads;
  every such file was reported as missing in all other languages.
- `jxt check` keeps language files of the same name in `language/` and
  `administrator/language/` apart, e.g. `com_example.ini` of a component;
  the second file replaced the first, so its constants were reported as
  undefined. The messages of `language/files` and `language/keys` name the
  target path of the file.
- The names of layout overrides of a template (`TPL_X_MOD_MENU_LAYOUT_…`,
  `TPL_X_COM_CONTENT_ARTICLE_LAYOUT_…`) are no longer reported as unused.
- The upper-cased `<name>` of the manifest (`LIGHTNING` for the template
  `lightning`), which Joomla translates, is no longer reported as unused.
- `jxt check` resolves the asset URIs of templates as Joomla does: classic
  templates (without `<inheritable>` or `<parent>`) keep their files in
  `templates/<name>/`, which were skipped without a warning, and files a
  child template takes from its parent or from the media of another
  extension are no longer reported as missing.
- `jxt sync` points to `jxt bridge add` only when a file operation fails,
  no longer when the extension is not installed or a file listed in the
  manifest is missing.

## [0.4.0] - 2026-10-22

### Added

- Plugins: the commands support manifests with `type="plugin"`. The install
  directory is `plugins/<group>/<element>/` with the group from the attribute
  `group` and the element from the attribute `plugin` in `<files>`; language
  files go to `administrator/language/` as with the Joomla installer. The ZIP
  file is named `plg_<group>_<element>-<version>.zip`, and `jxt check` expects
  the prefix `PLG_<GROUP>_<ELEMENT>_` for language constants.
- Components: the commands support manifests with `type="component"`. The
  element is `<element>` or `<name>` with the prefix `com_`; `<files>` goes to
  `components/`, `<files>` in `<administration>` together with the manifest
  and the install script to `administrator/components/`, `<files>` in `<api>`
  to `api/components/`, and `<languages>` in `<administration>` to
  `administrator/language/`. `jxt sync` deletes leftovers in all three
  directories and warns when the menu items in `<administration>` change.
- `jxt bridge add|remove|status`: installs a small Joomla console plugin
  (`plg_console_jxt`, shipped with jxt) on the test site. If it is installed,
  `jxt sync` writes the files as web server user via the Joomla CLI
  (`jxt:apply`), refreshes the manifest cache of the extension and updates an
  older bridge first; a newer one is used, so that projects with different jxt
  versions do not reinstall it in turn. It needs three more sudoers rules,
  which jxt prints if they are missing.
- `jxt install --clean`: deletes the leftovers that `jxt install` lists after
  the installation, via the jxt bridge if it is installed, otherwise directly.

### Changed

- A failed file operation of `jxt sync` now points to `jxt bridge add` instead
  of `jxt install`.
- The leftover report of `jxt install` points to `--clean` instead of
  `sudo -u www-data rm -r`.
- The output names templates like Joomla (`tpl_<name>`) instead of only
  `<name>`, as it already did for the ZIP file.
- The element name follows the Joomla installer more closely: modules and
  templates use `<element>` if present; the template name is reduced to the
  characters of Joomla's `cmd` filter (`My Template` becomes `mytemplate`
  instead of `my_template`); the attributes `module` and `plugin` only count
  in the first `<files>`; a module without `<element>` or `module` attribute
  is rejected instead of falling back to `<name>`, since Joomla could not
  install it.
- `jxt sync` skips language files of languages that are not installed on the
  server, as the Joomla installer does, instead of creating the language
  directory, and lists them.
- A language file without the attribute `tag` is rejected with a clear message
  instead of "Invalid language tag".

### Fixed

- Every child of `<files>` and `<media>` except `<folder>` is a file, as in the
  Joomla installer; files listed as `<file>` were missing from the ZIP and the
  sync.
- The attribute `client` of a `<language>` entry is respected.

## [0.3.0] - 2026-10-18

### Added

- `jxt install --zip <file>`: installs an existing ZIP file (e.g. of an older
  version) instead of building a new one; name and version are read from the
  manifest in the ZIP.
- `jxt check`: checks the extension before a release – manifest (semantic
  version, missing, unlisted and junk files), whitespace according to
  `.editorconfig`, `CHANGELOG.md` in the format of Keep a Changelog, asset
  versions against the last release tag and language files. Whether the
  manifest version is already tagged decides between pre-check and final
  check. Exit code 1 for errors (`--strict`: also for warnings), 2 for an
  invalid call; `--release` turns problems that only matter for a release
  (notices otherwise) into errors.
- `jxt check --tag <tag>`: checks the committed files of a Git tag as the
  final check of that release, e.g. in CI on tags; the tag must name the
  manifest version (`manifest/tag-version`).
- `jxt check --exclude/--include` and the configuration keys `check_exclude`
  and `check_include` in `.jxt.json` to skip checks, groups or `all`; the
  command line overrides `.jxt.json`.
- `jxt check --format=github`: prints the findings as GitHub Actions
  annotations at file and line, with paths relative to the repository root.
- `jxt install` lists leftovers after a successful installation: entries in
  the extension's own directories that the installed ZIP file does not
  contain, e.g. a class deleted from a folder listed in the manifest, which the
  Joomla installer keeps. Nothing is deleted.
- `jxt build --tag <tag>`: builds the ZIP file from the committed files of a
  Git tag (via `git archive`) instead of the working copy, so uncommitted
  changes do not end up in a release ZIP. Warns if the tag does not name the
  manifest version.
- `jxt install --tag <tag>`: builds the ZIP file from a Git tag like
  `jxt build --tag` and installs it, e.g. to test the update from the last
  release.
- `jxt config set` accepts several values, also comma-separated, for list
  keys.

### Changed

- `symfony/polyfill-mbstring` is now a direct dependency; jxt used the
  `mb_*` functions without requiring `ext-mbstring` and relied on it coming in
  through `symfony/console`.

## [0.2.0] - 2026-10-06

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
- `composer.lock` is no longer included in the package archive.

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

[Unreleased]: https://github.com/smarta-lab/jxt/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/smarta-lab/jxt/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/smarta-lab/jxt/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/smarta-lab/jxt/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/smarta-lab/jxt/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/smarta-lab/jxt/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/smarta-lab/jxt/releases/tag/v0.1.0
