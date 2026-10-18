# Architecture

## Background

The package replaces a hand-written deployment shell script that was copied into
each extension project. It had three problems:

- Paths and the module name were hard-coded, so the script could not be reused.
- An exclude list controlled what was copied, so new development files ended up
  on the server.
- Without `rsync --delete`, deleted or renamed files remained on the server.

Requirements for the replacement:

1. Find and parse the manifest (`mod_*.xml`, `templateDetails.xml`, …) in the
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

- PHP 8.4+ with `ext-zip` (`ZipArchive`), `ext-simplexml`/`ext-libxml`
  (manifests) and `ext-posix`; `symfony/console` 8.x,
  `symfony/polyfill-mbstring` (so `ext-mbstring` is optional); `git` for
  `--tag` and the release comparisons of `jxt check`. Quality tools: PHPUnit,
  PHPStan (level max), PHP_CodeSniffer (PSR-12).
- Package name `smarta-lab/jxt`, namespace `SmartA\Jxt`. Extension projects
  require it as a Composer dev dependency; until it is published on Packagist,
  via a `path` or `vcs` repository.
- Linux or WSL only: `ext-posix` is needed to find the developer's home
  directory when jxt is started via sudo, and `jxt install` relies on `sudo`
  unless `web_user` is `null`.
- A single binary `jxt` with subcommands (`install`, `sync`, `build`, …)
  instead of one binary per command, to avoid name collisions in `vendor/bin`.
  The name does not contain "Joomla" so that it is not mistaken for an official
  Joomla! project.
- Supported extension types: modules (site and administrator, depending on
  `client`), templates and plugins. Plugins fit the model of one install
  directory (`plugins/<group>/<element>/`) plus `media/`. Components have
  several install directories with their own `<files>` sections
  (`administrator/components/`, `components/`, `api/`) and SQL files, so the
  deployment plan would need more than one install directory; packages
  (`pkg_`) consist of several manifests.

## Core idea: the deployment plan

The manifest parser turns the manifest into a *deployment plan*: a list of
"source → target" mappings for the manifest file itself, `<files>`,
`<scriptfile>`, `<languages>` and `<media>`. Sources are relative to the
extension directory (the directory of the manifest, usually the project
directory), targets relative to the Joomla root. `jxt sync` and `jxt build` use
the same plan, so the server and the ZIP always contain the same files.

The plan also records:

- **Exclusive directories:** the install directory (`modules/<element>/`,
  `templates/<name>/`, `plugins/<group>/<element>/`) and
  `media/<destination>/`. They belong to the extension alone.
- **Source directories:** the `folder` attributes of the sections (and the first
  path segment of language files without `folder`). Files in them that the
  manifest does not list are reported.

The element name follows the rules of the Joomla installer: for modules the
`module` attribute in `<files>`, otherwise `<name>`; for templates `<name>` in
lower case with spaces replaced by `_`; for plugins the `plugin` attribute in
`<files>`, unchanged and without a fallback, since the installer would
otherwise install into the group directory itself. The installer ignores
`client` for plugins and copies their language files to
`administrator/language/`. The name Joomla uses for language files and
constants (`mod_x`, `tpl_x`, `plg_<group>_<element>` in lower case) also
names the ZIP, the prefix of the language constants checked by `jxt check`
and the extension in the output.

Because `jxt sync` deletes files on the server, the parser rejects absolute
paths, paths containing `..`, and invalid element names, plugin groups or
language tags.

## State: the installed manifest

`jxt sync` needs to know what it deployed last time. Instead of a separate state
file, it uses the manifest installed on the server, e.g.
`/var/www/html/modules/mod_x/mod_x.xml`. Since `jxt sync` copies the manifest on
every run, it always describes the last deployed state. If it is missing, the
extension has not been installed yet, and `jxt sync` asks for `jxt install`.

Deletion rules:

- In exclusive directories of the old and the new manifest, the actual content
  on the server is compared with the new plan. Everything else is deleted,
  including directories that were removed from the manifest entirely.
- In shared directories (`language/de-DE/`), only files listed in the old
  manifest and missing from the new one are deleted. Files of other extensions
  are never touched.

## Copying versus installing

`jxt install` runs the real Joomla installer, `jxt sync` only copies files. An
installation via the Joomla installer also writes to the database and caches:

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

## Permissions: installing as the web server user

The files of the test site belong to the web server user (`www-data`), so a
developer cannot write them directly. Alternatives considered:

- **jxt as root** (`sudo jxt sync` with `chown`, formerly `sjxt`): rejected.
  It runs the project's Composer autoloader as root, i.e. the `autoload.files`
  of every installed package including dev dependencies, and every file in
  `vendor/` is writable by the user. A compromised package or a manipulated
  file gains root on the next run.
- **Shared group with setgid directories:** not sufficient. `chmod` requires
  ownership, the Joomla installer creates directories with a fixed mode 0755
  (`Folder::create()`), so directories created by a backend installation are
  not group-writable; a umask or default ACLs do not help against an explicit
  mode.
- **PHP-FPM running as the developer:** works without any sudo, but gives the
  test site, including third-party extensions, access to the developer's home
  directory.
- **A custom Joomla extension with an upload endpoint:** needs no sudo, but is
  an upload-and-execute endpoint that needs authentication and must never reach
  a production site, plus a second project and protocol to maintain.

Chosen: `jxt install` runs the Joomla CLI shipped with Joomla
(`cli/joomla.php extension:install`) via `sudo -n -u <web_user>`. A sudoers
rule allows exactly this command without password. Privileges only go down
(developer → `www-data`, never root), the web server needs no access to the
home directory, and the result is a real installation. The ZIP is copied to
`/tmp` with mode 0644 because `www-data` cannot read the project; the name keeps
the `.zip` extension, which the Joomla archive handling relies on. `sudo -n`
makes a missing rule fail instead of prompting, and the command then prints the
matching rule. The PHP binary is `PHP_BINARY`, which must match the rule
exactly, since sudo compares the command path.

Without the jxt bridge (next section), `jxt sync` runs with the developer's own
permissions and is meant for test sites the developer can write to. Modes are
set for the extension's files and directories and for newly created
directories; existing shared directories such as `language/de-DE/` are left
unchanged.

If jxt is still started via sudo, `HOME` points to `/root`, so the home
directory for the global configuration is determined via `SUDO_USER`, and
`jxt config` refuses to write so that configuration files are not owned by
root.

## The jxt bridge: sync as web server user

`jxt sync` copies only the changed files, which makes quick iterations on CSS,
JavaScript or layouts possible without a ZIP, the installer and the database.
On sites whose files belong to the web server user it needs a way to write as
that user. The jxt bridge is a Joomla console plugin (`plg_console_jxt`) in
`plugin/` of the jxt package; jxt plans as the developer, and the plugin
applies the plan with `jxt:apply`, run via `sudo -n -u <web_user>` like
`jxt install`. Joomla loads console plugins only in `cli/joomla.php`, so there
is no web endpoint.

- **Separate runtimes:** the plugin runs as `www-data` in Joomla's PHP. It
  cannot load jxt's autoloader (the home directory is not readable for
  `www-data`, and `www-data` should not run project code) and uses Joomla's
  console instead of jxt's `symfony/console`. It therefore has its own
  namespace `SmartA\Plugin\Console\Jxt` outside jxt's PSR-4 autoload, and
  `plugin/` is part of the Composer package. The Joomla-independent classes
  (`Plan\…`) are autoloaded in jxt's tests only, which check that jxt's
  `PlanStager` and the plugin's `PlanReader` agree on the format. The plugin
  avoids PHP features newer than Joomla's minimum version.
- **Trust:** whoever may run `extension:install` as `www-data` can already run
  any code as `www-data`. The plugin's checks therefore guard against mistakes
  in jxt, not against attacks: it does not derive the plan from manifests, it
  executes it and only checks that every target is a normalized relative path
  in a directory that belongs to a single extension (`modules/<element>/`,
  `plugins/<group>/<element>/`, `media/<x>/`, `language/<tag>/<file>`, …) or
  is `administrator/cache/autoload_psr4.php`.
- **Staging:** jxt copies the new and changed files and `plan.json` into a
  directory below `/tmp` with modes 0755/0644, readable for the web server
  user but writable only by the developer, and removes it afterwards. The
  location is a constructor argument, so that a container setup could use a
  directory the container can see.
- **Version check instead of a protocol:** the plugin's manifest has its own
  version. jxt compares the bundled manifest with the installed one
  (`plugins/console/jxt/jxt.xml`, readable without sudo) and reinstalls the
  bundled plugin when they differ, so the plan format never needs to be
  backwards compatible. A test fails when `plugin/` changed since the last
  release tag without a higher version; during development, `jxt install` in
  `plugin/` updates the plugin without a new version.
- **Activation:** the Joomla installer leaves new plugins disabled (except
  editors), and the Joomla CLI only loads enabled ones. The plugin's install
  script enables it in `postflight`. The installer has cached the list of
  plugins before that point, so with caching enabled the plugin would stay
  invisible until the cache expires; the script therefore clears the cache
  group `com_plugins`.
- **When `jxt sync` uses it:** whenever the bridge is installed on the site,
  not depending on `web_user`. Sites the developer can write to, e.g. a
  container whose files are shared with the host, keep working without sudo,
  even if `web_user` keeps its default. The first installation is explicit
  (`jxt bridge add`), since it adds an extension to the site and needs sudoers
  rules; updates are automatic, since the developer agreed by installing it.
- **Removing:** the plugin reports its extension ID (`jxt:status`), and jxt
  passes it to `extension:remove -n`; without `-n`, the Joomla CLI asks for
  confirmation. Letting the plugin uninstall itself was rejected.
- **Manifest cache:** after applying a plan, `jxt:apply` refreshes the manifest
  cache of the extension, so `jxt sync` no longer needs to point to "Refresh
  Cache".

## Leftovers after an installation

The Joomla installer does not remove files from folders listed as a whole
(`<folder>src</folder>`), so leftovers accumulate, especially when switching
between a release (`--tag`, `--zip`) and the working copy. Only the files of
the installed ZIP tell which entries belong to the extension: the installed
manifest lists the folder but not its files, and the working copy can differ
from the installed state even with the same version (commits after the tag,
uncommitted changes). A separate command such as `jxt clean` could therefore
only compare against a guessed state.

So `jxt install` reports the leftovers itself, right after a successful
installation: `PackagePlanExpander` expands the plan of the manifest in the ZIP
against the ZIP entries, and `LeftoverFinder` compares the exclusive
directories of this manifest and of the manifest installed before with the
site. Reporting only needs read access and is the default. Deleting is the
option `--clean`, since a harmless default call should not delete files after
an update; it hands the same list as a plan with deletions only to the jxt
bridge, or deletes directly without it, as `jxt sync` does. For templates, the
list includes overrides and `user.css`/`user.js` in the template directory; an
exception for them was not added, since it would also hide real leftovers, and
the plain `jxt install` shows the list before.

## Building and checking a tag

`jxt build --tag`, `jxt install --tag` and `jxt check --tag` export the project
directory of the tag with `git archive` into a temporary directory and run the
normal build or check there, so the plan, the ZIP, the warnings and the checks
work as for the working copy.

- **`git archive` instead of a checkout:** it does not touch the index or the
  work tree and needs no `git worktree` that would have to be cleaned up. It
  leaves out files marked `export-ignore`; a file listed in the manifest is
  then reported as missing.
- **Configuration:** the `manifest` setting describes the layout of the
  repository, so it comes from the `.jxt.json` of the tag; the output
  directory belongs to the working copy.
- **Only tags:** the value must be an existing tag and is passed as
  `refs/tags/<tag>`, so neither a branch nor a Git option can be given.
- **Version mismatch:** for `build` and `install`, a tag that does not name the
  manifest version is a warning, not an error; the ZIP name always comes from
  the manifest. For `check`, it is an error (`manifest/tag-version`), since the
  check is about the release.
- **Git for checks:** the checks still need the repository for tags and asset
  comparisons, so `GitRepository::atTag()` gives a view of the project's
  repository in which the file list (`git ls-tree`) and the changes since the
  baseline (`git diff <baseline> <tag>`) refer to the tag. The files listed by
  Git but left out by `export-ignore` are skipped, since they are missing from
  the export.

## Checks: design decisions

`jxt check` only runs checks that hold for any extension or derive their
convention from files that already exist (`.editorconfig`, the changelog
format, Git tags). There are no opt-in checks, since hardly anyone finds them;
a check that rests on a guess (`language/unused`) only reports notices.

- **Mode from the tags:** jxt does not ask for a version, it looks whether the
  manifest version already has a tag (`ReleaseDetector`); without Git, a dated
  heading in `CHANGELOG.md` counts as released. `--tag` is always the final
  check of that tag; on a checkout of the tag itself, the version is tagged,
  so without `--tag` it would be a pre-check. `--tag` does not imply
  `--release`: the two stay independent, as `--release` only changes
  severities.
- **`--release` separate from `--strict`:** `--strict` is about the state of
  the code, `--release` about the preparation of the release. Release-only
  problems are notices without `--release`
  (`CheckContext::releaseSeverity()`). As warnings, `--strict` would turn them
  into errors, and a CI run on every push would fail whenever the release is
  not prepared yet.
- **Asset versions against the last release tag:** what holds in general is
  only that the `version` of an asset must change with its file (cache
  busting). Whether it equals the extension version is a convention, so
  `assets/version-coupled` only reports a notice.
- **Asset URIs via the targets of the plan** instead of `<media destination>`:
  this covers modules (`mod_x/file.js`) and templates (`template.css`) alike,
  including `.min` variants.
- **Files of the whitespace checks:** without Git, hidden files in the project
  directory are skipped; they are mostly tool files that Git would ignore
  (`.envrc`, `.phpunit.result.cache`).
- **`indent_style = tab`:** two or more spaces at the start of the indentation
  are a violation, a single one (` * ` in doc comments) is not.
- **Implicit language constants:** the bare prefix (the extension name) and
  `<PREFIX>_POSITION_…`/`<PREFIX>_LAYOUT_…` are used by Joomla itself and never
  reported as unused.
- **Selecting checks** works as in Ruff, ShellCheck or PHP_CodeSniffer:
  `--exclude` and `--include` with `all`, a group or a single check; the order
  does not matter. The command line overrides `.jxt.json`, within one source
  the more specific name wins. So `--include=all` runs everything the project
  excludes, and `--exclude=all --include=<check>` runs only that check.
  Alternatives considered:
  - the more specific name first, the source only for the same name (the first
    implementation): a single check excluded in `.jxt.json` could not be
    switched back on with `--include=all` or with its group, which is what
    such a call means;
  - `<group>/all` instead of the bare group: groups never contain `/`, so the
    bare name is unambiguous, and the question which rule wins is the same;
  - one list with signs in the given order (`-manifest,+manifest/…`): Symfony
    Console reads values starting with `-` as options, quotes do not help;
  - a map from check to setting as in ESLint: only needed if projects should
    change severities;
  - global lists: the result would depend on the machine, so the lists exist
    only in `.jxt.json` and CI checks the same as a local run.
- **File and line are fields of `Finding`**, not part of the message, so that
  `--format=github` can place annotations; the text output puts the file in
  front of the message. Paths are relative to the project directory, the
  GitHub output adds the path of the project directory in the repository
  (`git rev-parse --show-prefix`), since GitHub resolves them from the
  repository root. It is empty when the project directory is the repository
  root; without it, annotations of a project in a subdirectory (e.g. several
  extensions in one repository) would not be attached to their files.
  Findings about the manifest itself (missing listed files, version) point to
  the manifest. JSON is left out until there is a concrete need.
- **No manifest is not a check:** without a readable manifest nothing can be
  checked, so it is an invalid call (exit code 2) and cannot be excluded.

### EditorConfig parser

jxt has its own parser instead of a Composer package.
`idiosyncratic/editorconfig` (unchanged since 2021) does not follow the
specification: `*` also matches `/`, `{a,b}` and `{n..m}` do not match, and
`[[ab].md]` makes the whole file unreadable. `armin/editorconfig-cli` builds on
it, and `editorconfig-checker/editorconfig-checker` only wraps the Go program.

`GlobTranslator` follows editorconfig-core-py; the glob cases come from
editorconfig-core-test. `ReferenceImplementationTest` compares them with the C
core (program `editorconfig`, skipped if it is not installed). Deliberate
deviations from the C core: `?` matches one character instead of one byte, and
an unclosed `[` as well as `*` inside `[...]` are literal instead of matching
nothing.

## Code structure

Namespaces below `SmartA\Jxt`:

- **`Manifest`:** `ManifestLocator` finds the manifest; `ManifestParser` creates
  `Manifest` (type, client, element, version, `DeploymentPlan`,
  `InstallerMetadata`); `PackageManifestReader` reads the manifest of an
  existing ZIP (`jxt install --zip`); `RelativePath` validates paths.
- **`Deployment`:** `DeploymentPlan` and `PlanEntry`; `PlanExpander` expands
  directories into files, `PackagePlanExpander` into the files of a ZIP;
  `UnlistedSourceFinder` finds files missing from the manifest.
- **`Build`:** `ProjectBuilder` builds the ZIP of the project from the working
  copy or a Git tag (`ProjectBuild`) for `jxt build` and `jxt install`;
  `PackageBuilder` creates the ZIP, `TagExporter` exports a tag into a
  temporary directory.
- **`Git`:** `GitRepository` reads tags, files and file states and archives
  tags, used by `jxt check` and `--tag`.
- **`Install`:** `JoomlaCli` runs commands of the Joomla CLI, as web server
  user via sudo, through a `ProcessRunner` (`SystemProcessRunner` uses
  `proc_open` without a shell); `JoomlaInstaller` hands it the ZIP.
- **`Bridge`:** `Bridge` builds, installs, removes and queries the bridge
  plugin and applies a sync plan through it; `PlanStager` writes the plan and
  the files for it.
- **`Sync`:** `SyncPlanner` compares project and server and creates a
  `SyncPlan`; `SyncExecutor` applies it via `Filesystem`. `LeftoverFinder`
  finds the entries in the exclusive directories that do not belong to the
  plan, for the deletions of `jxt sync` and the leftover report of
  `jxt install`.
- **`Check`:** `CheckContextFactory` collects the `CheckContext` (manifest,
  expanded files of the plan, `GitRepository`, `Changelog`, `ReleaseState` from
  the `ReleaseDetector`); one `Checker` per group adds `Finding`s to the
  `CheckReport`, which drops the checks excluded by `CheckSelection`. All
  checks are cases of `CheckName`. `GithubAnnotation` formats findings as
  workflow commands for `--format=github`.
- **`EditorConfig`:** `EditorConfigResolver` reads `.editorconfig` files
  (`EditorConfigFile`) and resolves the properties of a file; `GlobTranslator`
  turns section names into regular expressions as the reference implementation
  does.
- **`Configuration`:** `ConfigurationLoader` merges the global and project files
  (`ConfigurationFile`); `ConfigurationKey` holds defaults and validation rules,
  including list keys; `HomeDirectoryResolver` handles sudo.
- **`Command`:** Symfony Console commands `jxt install`, `jxt sync`,
  `jxt bridge`, `jxt build`, `jxt check`, `jxt config`; entry point `bin/jxt`.
- **`plugin/`** (namespace `SmartA\Plugin\Console\Jxt`): the bridge plugin;
  `Plan\PlanReader` validates the plan (`TargetPath`), `Plan\PlanApplier`
  applies it, `Console\ApplyCommand` and `Console\StatusCommand` are the
  commands, `script.php` enables the plugin.

Conventions:

- Domain errors implement `JxtException` and are reported as a message without a
  stack trace.
- PHP warnings of file functions are captured via `PhpErrors` and become part of
  the error message.
- `--dry-run` has no short option because `-n` is `--no-interaction` in Symfony
  Console.
- `jxt sync` compares files by size and xxh128 hash.
