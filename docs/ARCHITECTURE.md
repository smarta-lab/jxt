# Architecture

## Background

The package replaces a hand-written deployment shell script that was copied into
each extension project. It had three problems:

- Paths and the module name were hard-coded, so the script could not be reused.
- An exclude list controlled what was copied, so new development files ended up
  on the server.
- Without `rsync --delete`, deleted or renamed files remained on the server.

Requirements for the replacement:

1. Find and parse the manifest (`mod_*.xml`, `templateDetails.xml`) in the
   current directory, or the manifest configured in `.jxt.json` if the
   extension is in a subdirectory.
2. Find the Joomla test server through a configuration file.
3. Copy only the files and directories listed in the manifest and set file
   permissions.
4. Delete files that no longer belong to the extension.
5. Build an installable ZIP; the output directory is configurable and defaults
   to `dist/`.

### Existing packages

None of them covered the requirements, so a small package of its own was
written:

- [JoRobo](https://github.com/joomla-projects/jorobo) expects its own directory
  layout (`source/...`), links the extension via symlinks instead of copying it,
  and is too heavy for single, flat modules.
- [joomla-extension-builder](https://github.com/splrk/joomla-extension-builder)
  is based on Phing, is old, and can only build, not deploy.
- [vikijel/joomla-extensions-packager](https://github.com/vikijel/joomla-extensions-packager)
  only combines finished ZIPs into `pkg_` packages.
- [N6REJ/joomla-packager](https://github.com/N6REJ/joomla-packager) only works
  as a GitHub Action.

## Technology

- PHP 8.4+, `symfony/console` 8.x, `ZipArchive`; quality tools: PHPUnit, PHPStan
  (level max), PHP_CodeSniffer (PSR-12).
- Package name `smarta-lab/jxt`, namespace `SmartA\Jxt`. Extension projects use
  it as a Composer `path` or `vcs` repository; Packagist is not needed.
- A single binary `jxt` with subcommands (`sync`, `build`, `config`) instead of
  one binary per command, to avoid name collisions in `vendor/bin`. The name
  does not contain "Joomla" so that it is not mistaken for an official Joomla!
  project.
- Supported extension types: modules (site and administrator, depending on
  `client`) and templates. Plugins and components may follow.

## Core idea: the deployment plan

The manifest parser turns the manifest into a *deployment plan*: a list of
"source → target" mappings for the manifest file itself, `<files>`,
`<scriptfile>`, `<languages>` and `<media>`. Sources are relative to the
extension directory (the directory of the manifest, usually the project
directory), targets relative to the Joomla root. `jxt sync` and `jxt build` use
the same plan, so the server and the ZIP always contain the same files.

The plan also records:

- **Exclusive directories:** the install directory (`modules/<element>/`,
  `templates/<name>/`) and `media/<destination>/`. They belong to the extension
  alone.
- **Source directories:** the `folder` attributes of the sections (and the first
  path segment of language files without `folder`). Files in them that the
  manifest does not list are reported.

The element name follows the rules of the Joomla installer: for modules the
`module` attribute in `<files>`, otherwise `<name>`; for templates `<name>` in
lower case with spaces replaced by `_`.

Because `jxt sync` deletes files on the server, the parser rejects absolute
paths, paths containing `..`, and invalid element names or language tags.

## State: the installed manifest

`jxt sync` needs to know what it deployed last time. Instead of a separate state
file, it uses the manifest installed on the server, e.g.
`/var/www/html/modules/mod_x/mod_x.xml`. Since `jxt sync` copies the manifest on
every run, it always describes the last deployed state. If it is missing, the
extension has not been installed yet, and `jxt sync` asks for an installation
via the backend.

Deletion rules:

- In exclusive directories of the old and the new manifest, the actual content
  on the server is compared with the new plan. Everything else is deleted,
  including directories that were removed from the manifest entirely.
- In shared directories (`language/de-DE/`), only files listed in the old
  manifest and missing from the new one are deleted. Files of other extensions
  are never touched.

## Copying versus installing

An installation via the Joomla installer also writes to the database and caches:

- `#__extensions`: the extension entry with `manifest_cache` (version, author,
  …) and namespace data
- possibly `#__update_sites`, `#__schemas` (SQL updates) and `#__assets`
- `administrator/cache/autoload_psr4.php`: the PSR-4 namespace map built from
  the installed manifests

Consequences when only files are copied after the first installation:

- **No problem:** new, renamed or deleted classes, layouts, CSS/JS, language
  strings, new configuration fields (read from the XML at runtime), new
  directories below `src/`, and `joomla.asset.json`.
- **Outdated display:** the version in the extension manager comes from
  `manifest_cache`; "Refresh Cache" in the extension manager fixes it.
  `jxt sync` points this out.
- **Changed namespace or `<namespace path>`:** `autoload_psr4.php` is outdated
  and classes are not found. `jxt sync` compares old and new manifest and
  deletes the file; Joomla rebuilds it.
- **Not executed:** install and update scripts, SQL files and new update
  servers. `jxt sync` warns when these manifest sections or the referenced files
  change and recommends installing the ZIP.
- **Changed element name:** this is a new extension and requires a new
  installation.
- Default values of new parameters are not written to existing module instances,
  but that is also true for a real upgrade.

## Permissions and sudo

- Default: `jxt sync` runs with sudo, sets owner and group (e.g. `www-data`) and
  uses the modes 0755/0644.
- Alternative without sudo: the user is a member of the web server group, the
  modes are e.g. 0775/0664.
- `chown`/`chgrp` only run as root and only if `owner`/`group` are configured;
  modes are always set for the extension's files and directories and for newly
  created directories. Existing shared directories such as `language/de-DE/` are
  left unchanged.
- Under sudo, `HOME` points to `/root`, so the home directory for the global
  configuration is determined via `SUDO_USER`.
- `jxt config` refuses to write under sudo so that configuration files are not
  owned by root.

## Code structure

Namespaces below `SmartA\Jxt`:

- **`Manifest`:** `ManifestLocator` finds the manifest; `ManifestParser` creates
  `Manifest` (type, client, element, version, `DeploymentPlan`,
  `InstallerMetadata`); `RelativePath` validates paths.
- **`Deployment`:** `DeploymentPlan` and `PlanEntry`; `PlanExpander` expands
  directories into files; `UnlistedSourceFinder` finds files missing from the
  manifest.
- **`Build`:** `PackageBuilder` creates the ZIP.
- **`Sync`:** `SyncPlanner` compares project and server and creates a
  `SyncPlan`; `SyncExecutor` applies it via `Filesystem`.
- **`Configuration`:** `ConfigurationLoader` merges the global and project files
  (`ConfigurationFile`); `ConfigurationKey` holds defaults and validation rules;
  `HomeDirectoryResolver` handles sudo.
- **`Command`:** Symfony Console commands `jxt sync`, `jxt build`, `jxt config`;
  entry point `bin/jxt`.

Conventions:

- Domain errors implement `JxtException` and are reported as a message without a
  stack trace.
- PHP warnings of file functions are captured via `PhpErrors` and become part of
  the error message.
- `--dry-run` has no short option because `-n` is `--no-interaction` in Symfony
  Console.
- `jxt sync` compares files by size and xxh128 hash.
