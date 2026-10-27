# Architecture

- [Background](#background)
  - [Existing packages](#existing-packages)
- [Technology](#technology)
- [Core idea: the deployment plan](#core-idea-the-deployment-plan)
- [State: the installed manifest](#state-the-installed-manifest)
- [Copying versus installing](#copying-versus-installing)
- [Permissions: installing as the web server user](#permissions-installing-as-the-web-server-user)
- [The jxt bridge: sync as web server user](#the-jxt-bridge-sync-as-web-server-user)
  - [Plan format](#plan-format)
- [Test sites in containers](#test-sites-in-containers)
- [Named test sites](#named-test-sites)
- [Leftovers after an installation](#leftovers-after-an-installation)
- [Building and checking a tag](#building-and-checking-a-tag)
- [Build steps](#build-steps)
- [Checks: design decisions](#checks-design-decisions)
  - [EditorConfig parser](#editorconfig-parser)
- [Code structure](#code-structure)

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
  (manifests), `ext-dom` (HTML5 parser for the output of install scripts) and
  `ext-posix`; `symfony/console` 8.x,
  `symfony/polyfill-mbstring` (so `ext-mbstring` is optional); `git` for
  `--tag` and the release comparisons of `jxt check`. Quality tools: PHPUnit,
  PHPStan (level max), PHP_CodeSniffer (PSR-12).
- Package name `smarta-lab/jxt`, namespace `SmartA\Jxt`. Extension projects
  require it as a Composer dev dependency from Packagist.
- Linux or WSL only: `ext-posix` gives the current user and, when jxt is
  started via sudo, the developer's home directory. With `web_user`, the
  Joomla CLI runs via `sudo -n -u <web_user>`; without it, or with
  `cli_command`, it runs without sudo (see
  [Test sites in containers](#test-sites-in-containers)). Native Windows is
  not supported: besides `ext-posix`, `joomla_root` must be an absolute path
  starting with `/`, and the paths are built with `/`.
- A single binary `jxt` with subcommands (`install`, `sync`, `build`, …)
  instead of one binary per command, to avoid name collisions in `vendor/bin`.
  The name does not contain "Joomla" so that it is not mistaken for an official
  Joomla! project.
- Supported extension types: modules (site and administrator, depending on
  `client`), templates, plugins, components and libraries. Packages (`pkg_`)
  consist of several manifests and are not supported yet; the type `file` is
  not supported, since it may install files anywhere, which contradicts the
  exclusive directories that `jxt sync` deletes in and the targets the bridge
  accepts.

## Core idea: the deployment plan

The manifest parser turns the manifest into a *deployment plan*: a list of
"source → target" mappings for the manifest file itself, `<files>`,
`<scriptfile>`, `<languages>` and `<media>`. Sources are relative to the
extension directory (the directory of the manifest, usually the project
directory), targets relative to the Joomla root. `jxt sync` and `jxt build` use
the same plan, so the server and the ZIP always contain the same files.

The plan also records:

- **Exclusive directories:** the install directory (`modules/<element>/`,
  `templates/<name>/`, `plugins/<group>/<element>/`), for components the three
  directories `administrator/components/<element>/`, `components/<element>/`
  and `api/components/<element>/`, for libraries `libraries/<libraryname>/`,
  and `media/<destination>/`. They belong to the extension alone.
- **Source directories:** the `folder` attributes of the sections (and the first
  path segment of language files without `folder`). Files in them that the
  manifest does not list are reported.

The element name follows the rules of the Joomla installer: for modules
`<element>` unchanged, otherwise the `module` attribute in the first `<files>`
in lower case; for templates `<element>`, otherwise `<name>`, reduced to the
characters of the `cmd` filter (`A-Z0-9_.-`) and in lower case; for plugins the
`plugin` attribute in the first `<files>`, unchanged. Modules and plugins have
no further fallback, since the installer would abort or install into the
group directory itself. Components follow the rule of templates and get the
prefix `com_` if it is missing (`ComponentAdapter::getElement()`). The
installer ignores `client` for plugins and copies
their language files to `administrator/language/`; the `client` attribute of a
single `<language>` entry overrides this for any type. As in the installer,
every child of `<files>` and `<media>` except `<folder>` is a file (usually
`<filename>`, sometimes `<file>`). The name Joomla uses for language files and
constants (`mod_x`, `tpl_x`, `plg_<group>_<element>` in lower case, `com_x`)
also names the ZIP, the prefix of the language constants checked by `jxt check`
and the extension in the output.

Libraries take their element from `<libraryname>` unchanged, which may
contain `/` (`acme/example` installs into `libraries/acme/example/`). Their
manifest does not go into the install directory but to
`administrator/manifests/libraries/<libraryname>.xml`, renamed after the
element; this is also the installed manifest that `jxt sync` reads. Their
language files belong to the site, and their name in Joomla is
`lib_<libraryname>` with `_` instead of `/`. The installer only rejects the
names `php-encryption`, `phpass`, `src` and `vendor` as a whole; jxt rejects
them and `joomla` as the first segment, since a library such as
`vendor/composer` or `joomla` would make Joomla's own files or manifest part
of the library, and the bridge reserves the same names.

Components have one target directory per area: `<files>` goes to the site
directory, `<administration><files>` to the administrator directory and
`<api><files>` to the API directory; `<languages>` belongs to the site,
`<administration><languages>` to the administrator. The manifest and
`<scriptfile>` go to the administrator directory, which the installer also
uses as the base of the SQL files (`extension_root`), and the component is
registered with `client_id` 1. The installer creates all three directories even
if the manifest lists no files for one of them, so all three are exclusive.
The menu items in `<administration>` (`<menu>`, `<submenu>`) are only written
by the installer and count as installer sections for `jxt sync`. Other types
ignore `<administration>` and `<api>`.

Because `jxt sync` deletes files on the server, the parser rejects absolute
paths, paths containing `..`, and invalid element names, plugin groups or
language tags.

Several of these rules are not in Joomla's documentation of manifests (e.g.
`<file>` as a file, `client` on `<language>`, the `cmd` filter for template
names, that only the first `<files>` names a module or plugin); they follow
the installer adapters of Joomla 6.1 (`libraries/src/Installer`) and may
change without notice, so the tests pin them. Deliberate deviations from the
installer:

- **All sections:** for each area the installer only reads the first
  `<files>`, `<languages>` and `<media>`; jxt takes all of them, which only
  matters for unusual manifests.
- **Missing `folder`:** the installer falls back to the extension directory;
  jxt reports the listed files as missing, so the parser does not depend on
  the file system and typos are not hidden.
- **`<media>` without `destination`** (copied directly into `media/`) and
  **`<language>` without `tag`** (copied to `<client>/language/<path>`) are
  rejected: they would make shared directories exclusive or widen the targets
  the bridge accepts, and `jxt sync` and `--clean` would delete files of other
  extensions.
- **`MD5SUMS`:** the installer copies this file from the extension directory
  even if the manifest does not list it; jxt deploys it only if listed.
- **Missing language packs:** like the installer, `jxt sync` skips language
  files whose `language/<tag>/` does not exist on the site, and lists them.
- **Projects in a Joomla directory tree** (language files outside the
  directory of the manifest, ZIP built by JoRobo or similar) are not
  supported; they have their own build tool, and `jxt install --zip` installs
  its ZIP.

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

Install scripts output messages with `echo` or as Joomla messages, usually as
HTML for the backend; the Joomla CLI passes it on unchanged. `jxt install`
converts it into plain text (`InstallerOutput`, `HtmlToText`). Only lines with
tags of known HTML elements count as HTML, so a message such as "missing
`<extension>` element" stays as it is, and the lines of the Joomla CLI keep
their layout. Rejected: Composer packages such as `soundasleep/html2text`
(more than needed, a runtime dependency for a small feature),
`html2text/html2text` (GPL, jxt is MIT) and `league/html-to-markdown` (Markdown
syntax is noise in the console).

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

`web_user` has no default (until 0.5, it was `www-data`). With a default, an
unset `web_user` differed from an explicit one: the default gave way to
`cli_command`, while the same value set explicitly contradicted it, and
`null` had to mean "without sudo" for this key only, instead of "default" as
everywhere else. Without a default, the configuration says what happens, and
the price is one more key in the one-time setup next to `joomla_root`. So that
a forgotten `web_user` does not end in errors of the Joomla CLI or in files
owned by the developer, jxt refuses to run the Joomla CLI without sudo if the
developer cannot write to `joomla_root`, and suggests its owner as `web_user`.

Without the jxt bridge (next section), `jxt sync` runs with the developer's own
permissions and is meant for test sites the developer can write to. Modes are
set for the extension's files and directories and for newly created
directories; existing shared directories such as `language/de-DE/` are left
unchanged. The modes are fixed to those of the Joomla installer, 0755 for
directories (`Folder::create()`) and 0644 for files (the usual umask of the web
server user), so that `jxt sync` leaves the same state as `jxt install`. The
keys `dir_mode` and `file_mode` (until 0.5) were removed: `jxt install` never
used them, and the one case for other modes, a shared group, is rejected above.
If a site ever needs other modes, they would belong to the site.

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
- **Static analysis:** PHPStan analyses the Joomla-dependent files of the
  plugin with Joomla's autoloader if `joomla_root` of the test site in the
  global jxt configuration (`JXT_SITE` or `default_site`) contains a Joomla
  site (`phpstan.joomla.php`); otherwise, e.g.
  in CI, only `plugin/src/Plan`. PHPStan does not notice when the result of
  this include changes and would keep its cached container, which then lacks
  the Joomla bootstrap (errors such as "unknown class Joomla\DI\Container"
  after setting `joomla_root`). Both cases therefore use a temporary directory
  of their own (`phpstan-jxt` and `phpstan-jxt-joomla` in the system's
  temporary directory).
- **Trust:** whoever may run `extension:install` as `www-data` can already run
  any code as `www-data`. The plugin's checks therefore guard against mistakes
  in jxt, not against attacks: it does not derive the plan from manifests, it
  executes it and only checks that every target is a normalized relative path
  in a directory that belongs to a single extension (`modules/<element>/`,
  `plugins/<group>/<element>/`, `components/<element>/`, `libraries/<name>/`,
  `media/<x>/`, `language/<tag>/<file>`, …), is the manifest of a library
  (`administrator/manifests/libraries/<name>.xml`) or is
  `administrator/cache/autoload_psr4.php`.
- **Staging:** jxt copies the new and changed files and `plan.json` into a
  directory below `/tmp` with modes 0755/0644, readable for the web server
  user but writable only by the developer, and removes it afterwards. With
  `cli_command`, the directory is in the site's `tmp/` directory instead (see
  [Test sites in containers](#test-sites-in-containers)). The plan names its
  files relative to the staging directory, so the plugin does not care where
  that is.
- **Version check with a plan format:** the plugin's manifest has its own
  version. jxt compares the bundled manifest with the installed one
  (`plugins/console/jxt/jxt.xml`, readable without sudo) and installs the
  bundled plugin when the installed one is older. A newer one is used as it
  is: replacing it with every differing version would make projects with
  different jxt versions on one site reinstall the bridge in turn, since the
  Joomla installer allows downgrades. The plan therefore carries a format
  number (`PlanStager::FORMAT`); the plugin reads `PlanReader::MIN_FORMAT` to
  `PlanReader::FORMAT` and rejects others with a hint to update jxt or to
  install the bundled bridge with `jxt bridge add`. An incompatible change
  raises the format; the plugin keeps reading the previous one where that is
  easy. A test fails when `plugin/` changed since the last release tag without
  a higher version; during development, `jxt install` in `plugin/` installs
  any state without a new version, as does `jxt bridge add`. A hash over
  `plugin/` instead of the version was rejected: every development state
  would differ and trigger a reinstallation.
- **Activation:** the Joomla installer leaves new plugins disabled (except
  editors), and the Joomla CLI only loads enabled ones. The plugin's install
  script enables it in `postflight`. The installer has cached the list of
  plugins before that point, so with caching enabled the plugin would stay
  invisible until the cache expires; the script therefore clears the cache
  group `com_plugins`. Enabling it with `extension:enable <id>` was rejected,
  since it needs the ID and another sudoers rule.
- **When `jxt sync` uses it:** whenever the bridge is installed on the site,
  not depending on `web_user`. Sites the developer can write to, e.g. a
  container whose files are shared with the host, keep working without sudo,
  even if `web_user` is set. The first installation is explicit
  (`jxt bridge add`), since it adds an extension to the site and needs sudoers
  rules; updates are automatic, since the developer agreed by installing it.
- **Removing:** the plugin reports its extension ID (`jxt:status`), and jxt
  passes it to `extension:remove -n`; without `-n`, the Joomla CLI asks for
  confirmation. Letting the plugin uninstall itself was rejected.
- **Manifest cache:** after applying a plan, `jxt:apply` refreshes the manifest
  cache of the extension, so `jxt sync` no longer needs to point to "Refresh
  Cache".

### Plan format

`PlanStager` writes `plan.json` into the staging directory, `PlanReader`
validates it in the plugin. Format 1:

```json
{
    "format": 1,
    "directory_mode": 493,
    "file_mode": 420,
    "deletions": [
        {"target": "modules/mod_x/tmpl/old.php", "directory": false},
        {"target": "modules/mod_x/helper", "directory": true}
    ],
    "directories": ["modules/mod_x", "modules/mod_x/tmpl"],
    "files": [
        {"target": "modules/mod_x/tmpl/default.php", "source": "files/0"},
        {"target": "modules/mod_x/mod_x.xml", "source": null}
    ],
    "extension": {
        "type": "module", "element": "mod_x", "folder": "", "client_id": 0
    }
}
```

- `directory_mode`/`file_mode`: the modes, decimal in JSON (493 = 0755); since
  jxt fixed them to 0755/0644, always these values. They stay in the format so
  that installed bridges keep working.
- `deletions`: entries to delete, directory contents before the directory;
  `directory: true` removes an empty directory.
- `directories`: the extension's directories, parents first; missing ones are
  created, and their modes are set.
- `files`: all files of the extension; `source` is the staged copy
  (`files/<n>`), `null` means unchanged, so only the mode is set.
- `extension`: the entry in `#__extensions` whose manifest cache is refreshed
  (`folder` is the plugin group, `client_id` 0 for site, 1 for administrator
  and components).
- Every `target` is relative to the Joomla root and checked by `TargetPath`.
  For `jxt install --clean`, `files` and `directories` are empty.

The format is a plain integer. `PlanReader` ignores unknown keys, so a new
optional key that an older bridge may safely ignore needs no new format. A
change that an older bridge must not ignore, such as a new kind of operation or
a changed meaning of a field, raises `FORMAT` in `PlanStager` and `PlanReader`;
an older bridge then rejects the plan with a hint on how to resolve the
mismatch. A minor version would add nothing: a bridge can only apply a plan it
reads or reject it. Raise `MIN_FORMAT` only when reading an old format is no
longer worth the effort.

## Test sites in containers

On a site in a container (DDEV, Docker Compose, …), the PHP of the host usually
cannot run the Joomla CLI: it may lack the PHP version or extensions, and the
database host in `configuration.php` (often `db`) only resolves between the
containers. The files of such sites are usually shared with the host as bind
mount, so `jxt sync` without the bridge already worked where the developer may
write.

Chosen: the key `cli_command`, a list with the command that starts PHP for the
Joomla CLI, including `php` (e.g. `["docker", "exec", "-u", "www-data", "web",
"php"]`), and `cli_root`, the path of the site as that command sees it.
`JoomlaCli` appends `<cli_root>/cli/joomla.php` and the arguments and runs the
list without a shell, like every process of jxt. A plain list covers Docker,
Podman, `ddev exec -p <project>` and a different PHP on the host alike.

- **`web_user` stays:** it is the case in which jxt builds the command itself
  and can explain a failure: the sudoers rules need the exact PHP binary
  (`PHP_BINARY`), the absolute path of the script and escaped colons, and
  `sudo -n` must not be forgotten, or `jxt install` would wait for a password.
  A list could not carry these rules, nor be a default, since `PHP_BINARY`
  depends on the PHP running jxt. With `cli_command`, sudo is not used; an
  `web_user` next to it is rejected instead of being ignored silently. Like a missing `joomla_root`,
  the contradiction is only an error when the test site is used
  (`Configuration::testSite()`), so `jxt build` and `jxt check` are not
  affected. `jxt config` only warns about it: rejecting it there would block
  changes that take several calls, e.g. setting `cli_command` before removing
  `web_user`.
- **Rejected:** `web_user` as a special case of a command prefix (it would
  change the meaning of an existing key and lose the sudoers hints); a prefix
  without `php` (no different PHP on the host); keys for Docker such as a
  container name (tied to one tool); `docker cp` instead of a bind mount (tied
  to Docker, and jxt reads the installed manifests on the host anyway).
- **Files for the Joomla CLI:** the ZIP file of `jxt install` and the staged
  plan of the bridge go to `<joomla_root>/tmp`, which every Joomla site has,
  and the CLI gets them as `<cli_root>/tmp/…`. A key for a staging directory of
  its own was rejected: a separate mount inside the site, e.g.
  `./staging:/var/www/html/tmp/jxt`, is not visible below `joomla_root` on the
  host, so the paths could not be translated via `cli_root`. Where the
  developer may not write to `tmp/` (official image, files of `www-data`),
  `chmod 1777` as root in the container is enough, without sudo on the host;
  jxt names this in the error message. The mode survives restarts, since the
  image's entrypoint only changes owners when it installs Joomla.
- **The site as an object:** `TestSite` holds the directory, the web server
  user, the command and `cli_root`, so the commands no longer pass
  `joomla_root` and `web_user` separately. It is the place for further
  properties of the site.
- **Tests:** the commands are tested with recorded processes; there are no
  tests with Docker, since CI runs with `--fail-on-skipped`. DDEV (with
  `docker exec` and `ddev exec -p`) and the official Joomla image were tested
  by hand, including the bridge, `--clean`, `--tag` and a large component; a
  site in a named volume is rejected with a hint.

## Named test sites

The keys that describe the test site (`joomla_root`, `web_user`,
`cli_command`, `cli_root`) live only in the sites of `sites` in the
global configuration; at the top level of either file and in `.jxt.json`
they are an error, whose message shows the site that takes their values.

- **Why:** they describe the machine, not the project, and `cli_command`
  runs a command. In `.jxt.json`, a cloned repository could run commands
  through `jxt install` or `jxt sync`, and point `joomla_root` at any
  directory in which `jxt sync` deletes. Now `prepare` is the only key of the
  repository that runs commands, and it is a property of the project by
  design.
- **Sites by id:** `sites` is an object with the sites by id, like the
  services of Docker Compose; the id is just a name, without reserved values.
  It starts with a letter: `json_decode()` turns keys such as `"1"` into
  integers, so `{"0": {}}` would read like a list, and an id such as `-x`
  would look like an option. A duplicate id cannot be reported, since
  `json_decode()` keeps the last site; `jxt site add` and `rename` refuse
  existing ids, so this only happens when editing the file by hand. When
  writing, jxt encodes `sites` and every site as objects, so that a site
  without keys does not become `[]`. A site inherits
  nothing, otherwise a `web_user` would leak into a site with `cli_command`,
  the contradiction jxt rejects. Missing or contradicting keys of a site are
  only errors when the site is used, as before, so that `jxt config` can
  change a site in several calls.
- **`default_site`:** an optional global key with the id of the site used
  without `--site` and `JXT_SITE`, as tools with several targets usually have
  one (the default profile and `AWS_PROFILE` of the AWS CLI, `docker context
  use` and `DOCKER_CONTEXT`). Being one key, it needs no rule such as "at most
  one default". Without it, every call needs `--site` or `JXT_SITE`, for
  developers who only use named sites.
- **Selection:** `--site` before `JXT_SITE` before `default_site`; an
  empty `JXT_SITE` counts as unset. An unknown id is an error, never a silent
  fallback, which could install into another site. `bin/jxt` reads the
  variable and hands it to `ConfigurationLoader`, which reads no environment
  itself and stays testable. The output names a site that does not come from
  the default, since an environment variable is invisible. `JXT_SITE` usually
  comes from the `.envrc` of the project or of a Git worktree, which direnv
  only loads after `direnv allow`; this also covers a worktree that tests a
  bugfix on a site of its own.
- **`jxt site`:** adds, removes, renames and lists the sites. `jxt config`
  only changes the keys of an existing site, so that a typo in `--site` is an
  error instead of a new site, and keeps a site without keys; following the
  selection rather than always `default_site` means that `jxt config set`
  changes the site that the next `jxt sync` uses. The id is not a key of
  `jxt config`, since everything refers to a site by it: `jxt site rename`
  changes `default_site` with it and points out that references elsewhere
  (`JXT_SITE` in an `.envrc`, `--site` in scripts) stay unchanged. Searching
  and changing them was rejected (incomplete, and it would edit files jxt does
  not own), as was keeping the old id as alias, which would hide forgotten
  references instead of reporting them. `default_site` is set with
  `jxt config` like other keys, or with `jxt site add --default`; setting it
  automatically for the first site was rejected, since it would give a
  default to developers who do not want one.
- **Later tools:** projects refer to a site only by its id, so the definition
  of the sites could move to a tool of its own without changing projects.

Rejected:

- **A list of sites with an `id` each** (jxt 0.6): it came from the first
  implementation, whose default site had no id; with `default_site`, every
  site is referred to by its id, and the list only added possible errors (a
  missing id), while the order of the sites does not matter. For the list,
  jxt shows the same sites by id; this hint is removed before 1.0.
- **`jxt allow`**, an approval of a cloned `.jxt.json` like `direnv allow`:
  with jxt as a Composer dev dependency, the repository's code already runs
  before jxt reads its configuration, through the `scripts` of
  `composer install`, a different jxt via `repositories`, and the
  `autoload.files` of its `composer.json`, which `bin/jxt` loads with
  `vendor/autoload.php`. It would only look safe, and add friction on every
  change of `prepare` plus an option for CI that everyone would set. It may
  make sense once jxt can be installed globally, then with a hash of
  `prepare` only, per project path, and failing with `-n` and in CI.
- **Sites by project path** in the global file: a worktree would need a copy,
  a moved directory would silently fall back to the default site, and a
  project could not use several sites.
- **`.jxt.local.json`**, a file per working copy that is never versioned:
  `JXT_SITE` in the `.envrc` does the same without a third file.
- **`site` in `.jxt.json`:** harmless, since it only selects one of the
  developer's own sites, but ids are personal names that do not belong into a
  repository. Allowing it later breaks nothing.
- **The default site at the top level of the global file:** two places for
  the same keys.
- **The site without id as default site** (the first implementation), with
  `default` reserved for selecting it: a rule "at most one site without id"
  and a reserved word, while the file did not say which site is the default.
  `"id": "default"` as a second spelling of the same was rejected as well.
- **`"default": true` on a site:** again a rule "at most one", and the first
  boolean of the configuration; `default_site` refers to the id instead.
- **No default at all,** only `--site` and `JXT_SITE`: unusual for such tools,
  and developers without direnv would export `JXT_SITE` in their shell
  profile, a default that `jxt config` neither shows nor explains.

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
the plain `jxt install` shows the list before. Modules are not affected:
overrides and alternative layouts of a module live in the template
(`templates/<template>/html/mod_x/`), not in `modules/mod_x/`. Uninstalling and
reinstalling as a shortcut was rejected, since it also deletes the module
instances and their settings. Libraries have no leftovers: for an update, the
Joomla installer uninstalls the installed library first (including its media
and language files) and installs it anew, with a new extension ID.

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
- **Options, not arguments:** like `--tag`, the existing ZIP file of
  `jxt install --zip` is an option instead of a positional argument, so that
  an argument stays free for later features (e.g. a project directory).
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

## Build steps

Some projects generate files before they can be built (CSS from SCSS,
minified files, a manifest with the version filled in). The project setting
`prepare` lists commands that jxt runs in the project directory before
`jxt build`, `jxt install`, `jxt check` and `jxt sync`.

- **Name:** the steps prepare the files that jxt builds, checks or copies;
  that the name equals npm's lifecycle script `prepare` was accepted.
  `build_steps` reads as "only for `jxt build`", `steps` says nothing.
- **Before the manifest:** the steps run after loading the configuration (and,
  for `install` and `sync`, after checking the test site, so a configuration
  error does not wait for slow steps) but before jxt looks for the manifest,
  since the manifest may itself be generated.
- **Two cases, not one entry per command:** `build`, `install` and `check`
  deal with what is shipped and always run `run`; the ZIP of `install` must
  equal the one of `build`. Only `jxt sync` iterates quickly, so a step may
  name a replacement for it (`sync`) or be skipped there (`"sync": false`).
  Rejected: keys named after commands (`build` + `sync` reads as "only for
  `jxt build`" and suggests `install` and `check` keys), modes such as
  `release`/`dev`, and `command` instead of `run` (longer; `run` is familiar
  from GitHub Actions).
- **`jxt sync --dry-run`** runs the steps as well: otherwise the preview
  would compare outdated files, and the option only promises not to change
  the site.
- **Steps for `jxt check`:** without them, a generated manifest is not found
  and generated files are reported as missing. A CI that checks such a
  project needs its tools anyway.
- **Replacements instead of relying on Joomla:** marking optimizing steps and
  skipping them for `sync` was rejected. It would rely on Joomla falling back
  to the unminified file (`HTMLHelper` prefers `.min` files without debug mode
  and the plain file with it), which only works in debug mode, for relative
  asset URIs and for files named `.min.*`. A replacement step creates every
  file under its shipped name. Symlinks from the site to the sources were
  rejected as well: the web server user would need access to the developer's
  home, Apache's `SymLinksIfOwnerMatch` refuses links owned by another user,
  a link leaves the directories the bridge accepts, and a container does not
  see the target.
- **No shell:** commands are lists of arguments, as for `cli_command`; shell
  features need an explicit `["sh", "-c", …]`.
- **Only objects:** every step is an object with `run` and optionally `sync`,
  even where `run` alone would do. A short form (a plain command meaning
  `run`) would be a second way to write the same thing, and a list of steps
  would be hard to tell from a single command. `"sync": null` is rejected as
  ambiguous (it often means "nothing"). Both can be relaxed later without
  breaking anything; allowing them could not be withdrawn.
- **Tags:** with `--tag`, the steps of the tag's configuration run in the
  exported files, so the result does not depend on the working copy. Files
  that Git does not track (`node_modules/`) are missing there; a step can
  install them.
- **Output:** the steps write directly to the terminal, so tools show colors
  and progress; with `--quiet`, their output is collected for the error
  message. The steps get empty standard input, so nothing waits for input.
- **sudo:** the steps would create files owned by root, so jxt refuses to run
  them under sudo instead of switching to `SUDO_USER`, which would need
  another sudo rule.
- **Not added:** an option to skip the steps (`sync` per step covers watch
  processes and speed) or to run `run` for `sync` (`jxt install` tests the
  optimized result); placeholders replaced by jxt itself, since the source of
  the version differs between projects; environment variables for the steps.
  All of them could be added later without breaking anything.

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
  including `.min` variants. As in `HTMLHelper::includeRelativeFiles()`, a
  relative URI of a template is looked up in the template first: classic
  templates in `templates/<name>/`, inheritable and child templates in
  `media/templates/<client>/<name>/`. A file the extension does not ship is
  only reported if Joomla cannot find it elsewhere, i.e. not if a target
  belongs to another extension or a child template may take it from its
  parent.
- **Files of the whitespace checks:** without Git, hidden files in the project
  directory are skipped; they are mostly tool files that Git would ignore
  (`.envrc`, `.phpunit.result.cache`).
- **`indent_style = tab`:** two or more spaces at the start of the indentation
  are a violation, a single one (` * ` in doc comments) is not.
- **Implicit language constants:** the bare prefix (the extension name) and
  `<PREFIX>_POSITION_…`/`<PREFIX>_LAYOUT_…` are used by Joomla itself and never
  reported as unused; for templates also the names of layout overrides
  (`TPL_X_MOD_MENU_LAYOUT_…`, `TPL_X_COM_CONTENT_ARTICLE_LAYOUT_…`). Joomla
  upper-cases every key, so the `<name>` of the manifest is translated as
  well (the template `lightning` with `LIGHTNING`).
- **Language file names:** Joomla loads `<extension>.ini` and
  `<tag>.<extension>.ini`, so the language files of different languages are
  compared by their name without the tag in front, and per target directory,
  since a component often has a file of the same name in `language/` and in
  `administrator/language/`.
- **Language constants in the code:** PHP, XML, JS and SQL files count (SQL
  for values such as mail templates or statuses that are translated after
  reading them from the database), without comments (`CommentStripper`), so
  that commented-out code neither uses a constant nor needs it defined. PHP
  is tokenized exactly; for JS and SQL a regular expression skips string
  literals, which is enough without a full parser. Constants are found by the
  prefix; defined keys without it are searched literally, since the prefix
  pattern cannot find them.
- **Built language keys:** a constant followed by `.` or `+` is the start of
  keys, and the key of `Text::plural()` is used with a plural suffix; both are
  fixed patterns of the code itself. A literal that is just the prefix is
  ignored, since it would mark every key as used. Not added: the keys that
  Joomla builds from a prefix (messages of `AdminController`, mail templates,
  labels of fieldsets and fields plugins, tasks), a list that grows with every
  Joomla version and would only save notices; and checking site and
  administrator code against the language files of their own area, which
  would need the area of every code file and special cases (`.sys.ini` for
  the manifest, layouts and menus). The language check must not raise false
  errors, since `--strict` fails the CI on them; it does not aim to find every
  missing key.
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
  the manifest.
- **No JSON output:** people read the text, CI uses `--format=github`, and no
  tool needs the findings as data yet. Since `Finding` already holds check,
  severity, message, file and line as fields, JSON would only need another
  case of `CheckFormat` and a formatter like `GithubAnnotation`. It would
  become part of the stable interface, though, so it waits for a concrete
  consumer that shows which fields and which structure it needs.
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
  temporary directory. `Preparation` runs the prepare steps through a
  `StepRunner` (`SystemStepRunner`: `proc_open` in the project directory,
  output passed through or collected), also for `jxt check` and `jxt sync`.
- **`Git`:** `GitRepository` reads tags, files and file states and archives
  tags, used by `jxt check` and `--tag`.
- **`Install`:** `JoomlaCli` runs commands of the Joomla CLI, as web server
  user via sudo or via `cli_command`, through a `ProcessRunner`
  (`SystemProcessRunner` uses `proc_open` without a shell); `JoomlaInstaller`
  hands it the ZIP; `CommandLine` formats commands for the output.
- **`Site`:** `TestSite` describes the test site: directory, web server user,
  `cli_command` and `cli_root`, with the translation of paths and the staging
  directory for the Joomla CLI.
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
  workflow commands for `--format=github`; `CommentStripper` removes comments
  before the `LanguageChecker` looks for constants.
- **`EditorConfig`:** `EditorConfigResolver` reads `.editorconfig` files
  (`EditorConfigFile`) and resolves the properties of a file; `GlobTranslator`
  turns section names into regular expressions as the reference implementation
  does.
- **`Configuration`:** `ConfigurationLoader` merges the global and project files
  (`ConfigurationFile`) and selects the test site (`SiteSelection`);
  `ConfigurationKey` holds defaults, validation rules and the scope of each key
  (`KeyScope`), including list keys; `PrepareStep` validates and describes a
  step of `prepare`, `SiteEntry` a site of `sites`; `SiteList` changes the
  sites and `SiteFile` writes them with `default_site`, for `jxt config` and
  `jxt site`; `HomeDirectoryResolver` handles sudo.
- **`Command`:** Symfony Console commands `jxt install`, `jxt sync`,
  `jxt bridge`, `jxt build`, `jxt check`, `jxt config`, `jxt site`; `SiteOption` is the
  option `--site` of the commands that use the test site; entry point
  `bin/jxt`.
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
