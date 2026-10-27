# Usage

[Deutsch](USAGE.de.md)

- [Commands at a glance](#commands-at-a-glance)
- [jxt install – install on the test server](#jxt-install--install-on-the-test-server)
  - [Installing a Git tag](#installing-a-git-tag)
  - [Installing an existing ZIP file](#installing-an-existing-zip-file)
  - [Leftovers](#leftovers)
  - [Setup: sudo without password for the Joomla installer](#setup-sudo-without-password-for-the-joomla-installer)
- [jxt sync – copy files directly](#jxt-sync--copy-files-directly)
  - [What is copied](#what-is-copied)
  - [What is deleted](#what-is-deleted)
  - [First installation](#first-installation)
  - [Permissions](#permissions)
  - [Warnings and notes](#warnings-and-notes)
- [jxt bridge – sync as web server user](#jxt-bridge--sync-as-web-server-user)
- [Test sites in containers](#test-sites-in-containers)
  - [DDEV](#ddev)
  - [Official Joomla image](#official-joomla-image)
- [jxt build – build an installable ZIP](#jxt-build--build-an-installable-zip)
- [Build steps](#build-steps)
- [jxt check – check before a release](#jxt-check--check-before-a-release)
  - [Checking a Git tag](#checking-a-git-tag)
  - [Annotations in GitHub Actions](#annotations-in-github-actions)
  - [Excluding checks](#excluding-checks)
- [jxt site – manage the test sites](#jxt-site--manage-the-test-sites)
- [jxt config – show and change the configuration](#jxt-config--show-and-change-the-configuration)
- [Configuration](#configuration)
  - [Manifest](#manifest)
  - [Test sites](#test-sites)
- [Calling jxt without vendor/bin](#calling-jxt-without-vendorbin)

## Commands at a glance

```text
jxt install [--tag=<tag> | --zip=<file>] [--clean] [--site=<id>]
jxt sync [--dry-run] [--site=<id>]
jxt bridge [status | add | remove] [--site=<id>]
jxt build [--tag=<tag>]
jxt check [--release] [--strict] [--tag=<tag>] [--format=text|github]
          [--exclude=<checks>] [--include=<checks>]
jxt site [list | add <id> [--default] | remove <id> | rename <id> <new id>]
jxt config [show] [--site=<id>]
jxt config set <key> <value>... [--global] [--site=<id>]
jxt config unset <key> [--global] [--site=<id>]
```

Run all commands in the project directory of the extension, usually the
directory containing the manifest; jxt finds the manifest itself, see
[Manifest](#manifest).

In a project, call jxt as `vendor/bin/jxt` (or see
[Calling jxt without vendor/bin](#calling-jxt-without-vendorbin)). Without
`--site`, jxt uses the site of `JXT_SITE` or `default_site`, see
[Test sites](#test-sites). Every command also takes the general options of
Symfony Console, e.g. `-q` (only errors) and `-n` (no questions);
`vendor/bin/jxt help <command>` describes the options of a command.

## jxt install – install on the test server

```bash
vendor/bin/jxt install          # install
vendor/bin/jxt install --clean  # install and delete the leftovers
```

`jxt install` builds the ZIP file like `jxt build`, after running the
[build steps](#build-steps), and installs it with the Joomla installer,
without opening the backend in a browser:

1. The ZIP file is copied to `/tmp` with mode 0644, so the web server user can
   read it without access to the project.
2. The Joomla CLI installs it as the web server user (`web_user`):

   ```bash
   sudo -n -u www-data php <joomla_root>/cli/joomla.php extension:install --path=…
   ```

3. The copy in `/tmp` is deleted.

For a site in a container, the copy goes to the site's `tmp/` directory, and
the Joomla CLI runs via `cli_command` (see
[Test sites in containers](#test-sites-in-containers)).

```
mod_example-1.1.0.zip → /var/www/html
Install Extension
=================

 [OK] Extension installed successfully.

 [OK] mod_example 1.1.0 installed.
```

This is a real installation or update: install scripts and SQL files run,
update servers are registered, and the version in the extension manager and the
namespace cache are up to date. Joomla itself deletes files that were listed in
the old manifest and are missing from the new one; other leftovers remain (see
[Leftovers](#leftovers)).

For an update, the manifest needs `method="upgrade"`; otherwise Joomla refuses
to install over an existing extension. If the installation fails and the
attribute is missing, `jxt install` points this out.

The Joomla CLI is a console application, not a web application. An install
script that expects the latter, e.g. by declaring `$app` as `CMSApplication`,
fails there; such an extension can only be installed in the backend.

Install scripts often output HTML, which the backend displays. `jxt install`
converts it into plain text, wrapped at the width of the terminal like the
messages of jxt (at most 120 characters, 80 if the width is unknown); the
other lines of the Joomla CLI stay unchanged.

### Installing a Git tag

```bash
vendor/bin/jxt install --tag v1.0.0
```

With `--tag`, `jxt install` builds the ZIP from the committed files of a Git
tag, as `jxt build --tag` does (see
[jxt build](#jxt-build--build-an-installable-zip)), and installs it. This tests
exactly the released state, e.g. the update from the last release to the
working copy. `--tag` and `--zip` cannot be combined (exit code 2).

### Installing an existing ZIP file

```bash
vendor/bin/jxt install --zip dist/mod_example-1.0.0.zip
```

With `--zip`, `jxt install` installs an existing ZIP file instead of building a
new one, e.g. an older version to test the update from a previous release or to
go back to it. Relative paths refer to the project directory. The manifest of
the project is not needed; the extension name, the version in the success
message and the hint on `method="upgrade"` come from the manifest in the root
directory of the ZIP. The file must have the extension `.zip`, which the Joomla
installer relies on.

Joomla itself does not check the version: installing an older ZIP over a newer
installation is a downgrade, and SQL update files of the newer version are not
reverted.

### Leftovers

The Joomla installer does not remove files from folders that the manifest
lists as a whole (`<folder>src</folder>`), e.g. a class deleted from `src/`, nor
files from earlier deployments by other tools. After a successful installation,
`jxt install` therefore compares the directories owned by the extension
(`modules/<element>/`, `media/<destination>/`, including those of the manifest
installed before) with the content of the ZIP file it just installed and lists
everything else; a leftover directory is listed once with a trailing `/`:

```
 [WARNING] Not removed by the Joomla installer:
           modules/mod_example/helper/
           modules/mod_example/tmpl/old.php

 ! [NOTE] They no longer belong to the extension. jxt install --clean removes
 !        them.
```

Without `--clean`, nothing is deleted, and the exit code stays 0. The
comparison uses the ZIP file, not the working copy, so it is also correct with
`--tag` and `--zip`. When switching between a release and the working copy, the
next `jxt install` lists what the other state left behind.

With `--clean`, `jxt install` deletes the same entries after the installation,
like the deletions of `jxt sync`: via the
[jxt bridge](#jxt-bridge--sync-as-web-server-user) if it is installed on the
site, otherwise with your own permissions. The exit code is 1 if deleting
fails.

```
Removing leftovers of the Joomla installer via jxt bridge:
  - modules/mod_example/helper/
  - modules/mod_example/tmpl/old.php

 [OK] mod_example 1.0.0 installed, 2 leftovers removed.
```

For templates, the template directory is also the place of overrides created in
the backend (`html/`) and of `user.css`/`user.js`. They are not part of the ZIP
file and therefore count as leftovers, which `--clean` deletes, as `jxt sync`
does; check the list of a plain `jxt install` first.

### Setup: sudo without password for the Joomla installer

So that the files belong to the web server user, the Joomla CLI runs as that
user (`web_user`), which you set once for the [test site](#test-sites), after
adding it:

```bash
vendor/bin/jxt site add local --default
vendor/bin/jxt config set joomla_root /var/www/html
vendor/bin/jxt config set web_user www-data
```

`sudo -n` never asks for a password; a sudoers rule is needed once:

```bash
sudo visudo -f /etc/sudoers.d/jxt
```

```
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:install *
```

The user name (here `user`), the PHP binary and the path must match the call
exactly; if the rule is missing, `jxt install` prints the matching line. The
colon must be escaped in sudoers.

The rule only allows acting as `www-data`, not as root. Through an install
script it allows running arbitrary code as `www-data`; that is intended, since
this is exactly the code developed for the test server. The web server gets no
access to your home directory.

If PHP on the test server already runs as your own user, leave `web_user`
unset; jxt then runs the Joomla CLI as you, without sudo. If you cannot write
to `joomla_root`, jxt refuses this and suggests the owner of the site as
`web_user`. For a project that uses another site, see
[Test sites](#test-sites), for a site in a container
[Test sites in containers](#test-sites-in-containers).

## jxt sync – copy files directly

```bash
vendor/bin/jxt sync            # deploy
vendor/bin/jxt sync --dry-run  # only show what would be copied and deleted
```

`jxt sync` copies the files without the Joomla installer. This is faster and
also deletes leftovers. Your user must be allowed to write to the target
directories (see [Permissions](#permissions)); if the files belong to the web
server user, install the [jxt bridge](#jxt-bridge--sync-as-web-server-user)
once, and `jxt sync` writes them as that user via the Joomla CLI.

`jxt sync` compares the project with the test server and lists the changes:

```
mod_example → /var/www/html
  - media/mod_example/css/old.css
  + modules/mod_example/tmpl/new.php
  ~ modules/mod_example/src/Helper.php

 [OK] 2 copied, 1 deleted, 35 unchanged.
```

`+` new file, `~` changed file, `-` deleted file or directory (with a trailing
`/`).

Before comparing, `jxt sync` runs the [build steps](#build-steps), with their
replacements for `jxt sync`; also with `--dry-run`, which only leaves the test
site unchanged.

### What is copied

Exactly what the manifest lists, mapped the same way the Joomla installer does:

| Manifest section | Target on the server (module, site) |
|---|---|
| the manifest itself, `<files>`, `<scriptfile>` | `modules/<element>/` |
| `<languages>` | `language/<tag>/` |
| `<media destination="…">` | `media/<destination>/` |

Administrator modules go to `administrator/modules/` and
`administrator/language/`, templates to `templates/<name>/`. Plugins go to
`plugins/<group>/<element>/` with the group from the attribute `group` of
`<extension>` and the element from the attribute `plugin` in `<files>` (e.g.
`<folder plugin="example">services</folder>`); as with the Joomla installer,
their language files go to `administrator/language/`. Components (element
`com_<name>`) have three directories: `<files>` goes to
`components/<element>/`, `<files>` in `<administration>` together with the
manifest and `<scriptfile>` to `administrator/components/<element>/`, and
`<files>` in `<api>` to `api/components/<element>/`; `<languages>` goes to
`language/<tag>/`, `<languages>` in `<administration>` to
`administrator/language/<tag>/`. Libraries go to `libraries/<libraryname>/`
(the name from `<libraryname>` may contain `/`, e.g. `acme/example`), their
manifest to `administrator/manifests/libraries/<libraryname>.xml` and their
language files to `language/<tag>/`. The attribute `client` of a `<language>`
entry overrides the area for that file. Language files of a
language that is not installed on the server are skipped and listed, as the
Joomla installer does. Files are compared by size and content; unchanged files
are not copied. Names starting with `.git` (e.g. `.gitkeep`) are skipped.

### What is deleted

The manifest installed on the server describes the last deployed state.

- In the directories owned by the extension (`modules/<element>/`, the three
  directories of a component, `libraries/<libraryname>/`,
  `media/<destination>/`), everything that is not
  part of the new manifest is deleted, including whole directories that were
  removed from the manifest.
- In shared directories such as `language/de-DE/`, only files that were listed
  in the old manifest and are missing from the new one are deleted. Files of
  other extensions are never touched.

Use `--dry-run` to check the deletions before the first run on an existing
server.

### First installation

`jxt sync` only updates an extension that is already installed. If the installed
manifest is missing, it stops with an error. Install the extension once with
`jxt install`.

### Permissions

Without the jxt bridge, `jxt sync` runs with the permissions of your user. The
extension's files get mode 0644, its directories and newly created directories
0755, as the Joomla installer creates them; existing shared directories such
as `language/de-DE/` are left unchanged. Only the owner of a file may run
`chmod` on it; group permissions are not enough.

If a file operation fails, `jxt sync` points to `jxt bridge add`. With the
bridge installed on the site, the bridge applies the same plan as web server
user, with the same modes.

### Warnings and notes

Copying files is not the same as installing via the Joomla installer. `jxt sync`
therefore reports:

- **Changed version:** the extension manager shows the old version until you run
  `jxt install` or refresh it in "System → Manage → Extensions" with "Refresh
  Cache". The jxt bridge refreshes it itself, so there is no note then.
- **Changed namespace:** `administrator/cache/autoload_psr4.php` is deleted;
  Joomla rebuilds it.
- **Changed installer sections** (`<scriptfile>`, `<install>`, `<uninstall>`,
  `<update>`, `<updateservers>`, the menu items of a component in
  `<administration>`) or **new/changed install scripts and SQL
  files:** these are only processed by the Joomla installer. Run `jxt install`
  instead.
- **Files not listed in the manifest:** files in the source directories of
  `<media>`, `<languages>` (and `<files folder="…">`) that the manifest does not
  list, e.g. a new `media/svg/` directory. They would be missing on the server
  and in the ZIP. `jxt install` and `jxt build` show this warning as well.

Default values of new module parameters are not written to existing module
instances; this is also true for a real upgrade, so always read parameters with
a default: `$params->get('x', default)`.

## jxt bridge – sync as web server user

```bash
vendor/bin/jxt bridge add     # install the bridge on the test site
vendor/bin/jxt bridge status  # bundled and installed version, Joomla CLI check
vendor/bin/jxt bridge remove  # uninstall it again
```

The jxt bridge is a small Joomla console plugin (`plg_console_jxt`) shipped
with jxt. It adds the command `jxt:apply` to the Joomla CLI, which applies the
plan of `jxt sync` as web server user. With it, `jxt sync` also works on sites
whose files belong to the web server user, e.g. for quick iterations on CSS,
JavaScript or layouts without building and installing a ZIP.

- **Explicit installation:** `jxt bridge add` builds the plugin from the jxt
  package and installs it with the Joomla installer; the plugin enables itself.
  Afterwards it checks that the Joomla CLI runs the plugin's commands.
- **How `jxt sync` uses it:** if the bridge is installed on the site, `jxt sync`
  stages the changed files and the plan in a directory below `/tmp` (readable
  for the web server user) and runs `jxt:apply` via `sudo -n -u <web_user>`
  (as you without `web_user`), or in the site's `tmp/` directory via
  `cli_command` (see
  [Test sites in containers](#test-sites-in-containers)); the output says
  `via jxt bridge`. Without the bridge, `jxt sync` copies the
  files itself as before.
- **Updates:** if the installed bridge is older than the one shipped with your
  jxt, `jxt sync` installs the bundled version first and says so. A newer
  bridge is used as it is, so that projects with different jxt versions on one
  site do not reinstall it in turn. If it no longer reads the plan of your jxt,
  `jxt sync` stops and says so; update jxt in that project, or install its
  bridge with `jxt bridge add`.
- **Manifest cache:** after applying the plan, the bridge refreshes the
  manifest cache of the extension, so the extension manager shows the new
  version.
- **Safety:** the bridge only acts when called via the Joomla CLI, so it has no
  web endpoint. It only accepts targets inside the directories of extensions
  (e.g. `modules/<element>/`, `media/<destination>/`, `language/<tag>/<file>`)
  and rejects anything else, such as `configuration.php`. It is meant for
  development sites only; remove it with `jxt bridge remove` before copying a
  site elsewhere.

The bridge needs three more sudoers rules besides the one for `jxt install`
(see [Setup](#setup-sudo-without-password-for-the-joomla-installer)); if they
are missing, `jxt bridge` and `jxt sync` print all four lines:

```
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:install *
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:remove -n *
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php jxt\:apply *
user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php jxt\:status
```

`extension:remove` is used by `jxt bridge remove`, with `-n` because the Joomla
CLI otherwise asks for confirmation; `jxt:apply` and `jxt:status` are the
commands of the bridge. Each command needs its own rule, since sudo-rs (the
default `sudo` of newer Ubuntu releases) only accepts `*` as a whole argument,
not inside one such as `jxt\:*`. With `cli_command`, no sudoers rules are
needed.

`jxt bridge status` also shows how jxt runs the Joomla CLI (`Runs via`): via
sudo as web server user, as your own user or via `cli_command`.

## Test sites in containers

For a site in a container (DDEV, Docker Compose, …), the PHP of the host
usually cannot run the site's Joomla CLI: it may lack the PHP version or
extensions, and it cannot resolve a database host such as `db` that only exists
between the containers. `cli_command` runs the Joomla CLI in the container
instead. Such a site is usually one of several [test sites](#test-sites), here
with the id `example`:

```bash
vendor/bin/jxt site add example
vendor/bin/jxt config set joomla_root /home/user/sites/example --site=example
vendor/bin/jxt config set cli_command --site=example -- docker exec -u www-data example-joomla-1 php
vendor/bin/jxt config set cli_root /var/www/html --site=example
```

- **`cli_command`** is the command that starts PHP in the container. jxt
  appends `<cli_root>/cli/joomla.php` and the arguments and runs it without a
  shell and without sudo, for `jxt install`, `jxt bridge` and the bridge in
  `jxt sync`. `web_user` is not used then; setting it next to `cli_command`
  is an error in the commands that use the Joomla CLI;
  `jxt config set` only warns about it, so that you can change the keys in any
  order. In `jxt config set`, put `--` before the
  command, otherwise options such as `-u` are taken as options of jxt;
  `--site` goes before the `--`.
- **`joomla_root`** is the directory of the site on your machine, **`cli_root`**
  its path in the container (default: `joomla_root`). The site's files must be
  shared with your machine as bind mount: jxt reads the installed manifests
  there, and `jxt sync` compares with them. Sites in a named volume are not
  supported.
- **Files for the Joomla CLI:** jxt puts the ZIP file of `jxt install` and the
  plan of the bridge into the site's `tmp/` directory, which the container sees
  as `<cli_root>/tmp`, and removes them afterwards. You need write permission
  there; if it is missing, jxt says how to grant it.
- **User:** the files belong to whoever runs PHP in the container. Without
  `-u`, `docker exec` runs as the container's default user, which is root in
  many images, so the extension's files would belong to root.

If a call fails, jxt shows the command, so that you can try it by hand, e.g.
with `list` as argument. Other tools work the same way, as long as a command
starts PHP in the container, e.g. `podman exec`.

### DDEV

DDEV runs PHP as your own user, and the files of the site belong to you. The
web container is called `ddev-<project>-web`, and `docker exec` uses your user
by default. The site in the global file:

```json
{
    "sites": {
        "example": {
            "joomla_root": "/home/user/sites/example",
            "cli_command": ["docker", "exec", "ddev-example-web", "php"],
            "cli_root": "/var/www/html"
        }
    }
}
```

`["ddev", "exec", "-p", "example", "php"]` works as well; the project name is
needed, since jxt runs in the directory of the extension, not of the DDEV
project. If Joomla is in a subdirectory of the project (DDEV's `docroot`), add
it to both paths. `jxt sync` writes the files directly; the bridge is not
needed. `ddev stop` removes the containers; `docker exec` then reports
`No such container`.

### Official Joomla image

With the official `joomla` image and Docker Compose, the site is mounted with
e.g. `./site:/var/www/html`, Apache runs as `www-data`, and the files belong to
`www-data` (UID 33). The container is called `<project>-<service>-<number>`,
and `docker exec` runs as root by default, so `-u www-data` is required. The
site in the global file:

```json
{
    "sites": {
        "example": {
            "joomla_root": "/home/user/sites/example/site",
            "cli_command": ["docker", "exec", "-u", "www-data", "example-joomla-1", "php"],
            "cli_root": "/var/www/html"
        }
    }
}
```

Your user cannot write to the site, not even to `tmp/`. Allow it once, as root
in the container; the sticky bit keeps others from deleting your files, and
Joomla keeps using the directory:

```bash
docker exec example-joomla-1 chmod 1777 /var/www/html/tmp
```

Then install the bridge with `jxt bridge add`, so that `jxt sync` writes the
files as `www-data`.

## jxt build – build an installable ZIP

```bash
vendor/bin/jxt build               # from the working copy
vendor/bin/jxt build --tag v1.0.0  # from a Git tag
```

Creates `dist/<element>-<version>.zip` (templates:
`dist/tpl_<name>-<version>.zip`, plugins:
`dist/plg_<group>_<element>-<version>.zip`, components:
`dist/com_<name>-<version>.zip`) containing the manifest and all
files it lists, with their paths relative to the manifest. An existing file with
the same name is replaced. Like `jxt sync`, `jxt build` warns about files that
are not listed in the manifest.

The ZIP is built from the working copy, including uncommitted changes. With
`--tag`, it is built from the committed files of a Git tag instead, e.g. for a
release ZIP: jxt exports the project directory of the tag with `git archive`
into a temporary directory, so files marked `export-ignore` in
`.gitattributes` are missing as well. The manifest and the `manifest` setting
come from the `.jxt.json` of the tag, the output directory from the working
copy. If the tag does not name the manifest version (with or without a `v`
prefix), jxt warns but still builds the ZIP.

If the project generates files first, list the commands under `prepare` (see
[Build steps](#build-steps)); `jxt build` runs them before building.

## Build steps

Some projects generate files before they can be built: CSS from SCSS,
minified scripts, or a manifest with the version filled in. List the commands
in the project configuration under `prepare`; jxt runs them in the project
directory before `jxt build`, `jxt install`, `jxt check` and `jxt sync`:

```json
{
    "prepare": [
        {"run": ["npm", "run", "css"]},
        {
            "run": ["npm", "run", "minify"],
            "sync": ["npm", "run", "minify:copy"]
        },
        {"run": ["npm", "run", "images"], "sync": false}
    ]
}
```

- A step is an object with `run` and optionally `sync`, each a command
  written as a list of arguments. The commands run without a shell, as with
  `cli_command`; for pipes, `&&` or wildcards, write `["sh", "-c", "…"]`
  explicitly. Longer sequences are easier to maintain as a
  script in the project (an npm script, a PHP or shell script).
- `jxt build`, `jxt install` and `jxt check` always run `run`, since they deal
  with what is shipped. `jxt sync` runs `sync` instead if a step has one, or
  skips the step with `"sync": false`, e.g. because a watch process
  (`sass --watch`) keeps the files up to date. `"sync": null` is rejected as
  ambiguous: leave `sync` out to run the same command.
- The steps run in order, each with empty standard input; their output appears
  as it comes, after a line with the command (`> npm run css`). With
  `--quiet`, the output is collected and only shown in the error message. A
  step that fails (exit code other than 0) or cannot be started stops jxt
  before anything is built, checked or copied; `jxt check` then exits with
  `2`.
- `jxt install --zip` runs no steps, since it builds nothing. `jxt sync
  --dry-run` runs them, so that the preview compares the generated files.
- Under sudo, jxt refuses to run the steps, since the generated files would be
  owned by root.

The prepare steps run with your permissions, so a `.jxt.json` from someone
else runs commands on your machine, as Composer and npm scripts do. Look at
`prepare` before running jxt in a cloned project; it is the only key of
`.jxt.json` that runs commands, since the test sites, including `cli_command`,
are only defined in your global configuration. That does not make a cloned
project safe: `composer install` runs the scripts of its `composer.json`, and
`vendor/bin/jxt` loads the project's Composer autoloader, including the
`autoload.files` of its `composer.json`. In GitHub Actions, workflows on
`pull_request` get no secrets for pull requests from forks; only
`pull_request_target` would run their steps with secrets.

**Replacements for `jxt sync`:** a step that only optimizes (minifying,
compressing images) can be replaced for `jxt sync` by a faster step that
creates the same files without the optimization, so the test site has every
file under the name the extension refers to. Copying is more reliable than
deleting: e.g. `minify:copy` copies `style.css` unchanged to `style.min.css`.
The replacement overwrites the generated files in the working copy; if they
are committed, Git shows them as changed until the next `jxt build` or
`jxt install` generates them again.

**Generated manifest:** if the manifest itself is generated, e.g. with the
version filled in, point `manifest` to the generated file. The steps run
before jxt looks for the manifest:

```json
{
    "manifest": "build/mod_example.xml",
    "prepare": [{"run": ["php", "tools/build.php"]}]
}
```

**Git tags:** with `--tag`, the steps of the tag's `.jxt.json` run in the
temporary directory with the exported tag, so the working copy stays untouched
and the result does not depend on uncommitted changes. Files that Git does not
track, such as `node_modules/` or `vendor/`, are missing there; install them in
a step, e.g. `{"run": ["sh", "-c", "test -d node_modules || npm ci"]}`. jxt
exports only the project directory: if the `package.json` lies above it in the
repository, the steps cannot reach it with `--tag`.

**Checks:** `jxt check` checks the generated files like all others. With Git,
the EditorConfig checks skip files that Git ignores, which is usually the case
for generated files. `assets/version-changed` compares files with the last
release via Git, so it does not see changes of generated files that are not
committed.

## jxt check – check before a release

```bash
vendor/bin/jxt check            # pre-check or final check, see below
vendor/bin/jxt check --release  # final check before tagging a release
vendor/bin/jxt check --strict   # warnings count as errors, e.g. in CI
vendor/bin/jxt check --tag v1.2.0 --release  # final check of a tag
vendor/bin/jxt check --strict --format=github  # annotations in GitHub Actions
```

Mechanical checks of the extension, derived from the manifest and from files
that already exist in the project (`.editorconfig`, `CHANGELOG.md`, Git tags).
There is nothing to switch on: checks without a basis are skipped with a
notice. Judging the content (changelog entries, README) stays a manual review.
The [build steps](#build-steps) run first, so generated files are checked as
they would be shipped.

Whether the manifest version is already released decides the mode, shown in
the first line of the output:

- **Pre-check:** the version has a Git tag (`v1.2.0` or `1.2.0`, depending on
  the existing tags), so work on the next version is in progress.
- **Final check:** the version has no tag yet and is about to be released.
  Before the first release, this is always the case.
- Without Git, `CHANGELOG.md` decides: a dated heading for the version counts
  as released.
- With `--tag`, it is always the final check of that tag (see
  [Checking a Git tag](#checking-a-git-tag)).

Problems that only matter for a release (missing `CHANGELOG.md` heading, entries
left under `[Unreleased]`, asset file changed without a new version) are notices
in the final check and become errors with `--release`. So `--strict` covers the
state of the code and `--release` the preparation of the release: a CI run on
every push uses `jxt check --strict` without failing before the release is
prepared, the run on a tag uses `jxt check --tag <tag> --strict --release`. In a
pre-check, `--release` has no effect, which the output points out.

Exit codes: `0` without errors, `1` with errors (with `--strict` also with
warnings), `2` for an invalid call, configuration or manifest, or a failing
build step. Notices never
fail the check.

| Check | Reported when | Pre | Final |
|---|---|---|---|
| `manifest/version-semver` | `<version>` is missing or not a [semantic version](https://semver.org) | error | error |
| `manifest/tag-version` | with `--tag`: the tag does not name the manifest version (`v1.2.0` or `1.2.0`) | – | error |
| `manifest/listed-files` | a file or directory listed in the manifest is missing | error | error |
| `manifest/unlisted-files` | a file in a source directory is not listed in the manifest | warning | warning |
| `manifest/stray-files` | a junk file in a listed directory would end up in the ZIP (`.DS_Store`, `*~`, `*.orig`, hidden files) | warning | warning |
| `editorconfig/missing` | there is no `.editorconfig`; the group is skipped | notice | notice |
| `editorconfig/indent-style` | indentation contradicts `indent_style` | error | error |
| `editorconfig/end-of-line` | line endings contradict `end_of_line` | error | error |
| `editorconfig/final-newline` | the end of the file contradicts `insert_final_newline` | error | error |
| `editorconfig/trailing-whitespace` | trailing whitespace contradicts `trim_trailing_whitespace` | error | error |
| `editorconfig/max-line-length` | a line is longer than `max_line_length` (in Markdown, code blocks, tables, headings and lines with links are skipped) | warning | warning |
| `editorconfig/charset` | invalid UTF-8, or a byte order mark contradicts `charset` | error | error |
| `changelog/format` | `CHANGELOG.md` is missing or does not follow [Keep a Changelog](https://keepachangelog.com); the group is skipped | notice | notice |
| `changelog/last-release` | the manifest version is not the last release in the changelog | warning | – |
| `changelog/unreleased` | pre-check: number of entries under `[Unreleased]`; final check: `[Unreleased]` is not empty | notice | notice, error with `--release` |
| `changelog/release-heading` | there is no heading `## [X.Y.Z] - YYYY-MM-DD` for the version | – | notice, error with `--release` |
| `assets/json` | a `joomla.asset.json` is not valid JSON | error | error |
| `assets/uri` | the URI of an asset matches no file of the extension, resolved as Joomla does (a plain file name of a template in `templates/<name>/`, or in `media/templates/…` with `<inheritable>` or `<parent>`; external URIs, other extensions' media and files a child template takes from its parent are skipped); only a notice for `user.css` and `user.js` of templates, which site owners create themselves (as in Cassiopeia) | warning | warning |
| `assets/version-changed` | the asset's file changed since the last release tag, its `version` did not | notice | notice, error with `--release` |
| `assets/version-coupled` | a version equalled the extension version at the last release, but not the new one | – | notice |
| `assets/baseline` | no Git or no release tag yet, so asset versions are not compared | notice | notice |
| `language/parse` | a language file cannot be parsed | error | error |
| `language/files` | a language file is missing in one language, compared per target directory (as in Joomla, the language tag in front of the file name, `de-DE.mod_example.ini`, is optional) | error | error |
| `language/keys` | a key is missing in one language | error | error |
| `language/undefined` | a language constant used in PHP, XML, JS or SQL (outside of comments) is not defined; a constant followed by `.` or `+` must be the start of a defined key, the key of `Text::plural()` may also be defined only with a plural suffix (`_1`, `_MORE` …) | error | error |
| `language/unused` | a defined constant is not found in the code outside of comments (it may be built at runtime; keys starting with a concatenated constant or with the key of `Text::plural()`, the extension name, the `<name>` of the manifest, template positions and layouts, including layout overrides of templates, are never reported) | notice | notice |
| `composer/version-field` | `composer.json` has a `version` field | warning | warning |

The whitespace checks cover the tracked and untracked, not ignored files of
the Git repository; without Git, the files of the manifest and the non-hidden
files in the project directory. Binary files are skipped. Exclude files such as
third-party libraries in the `.editorconfig` with `unset`.

### Checking a Git tag

```bash
vendor/bin/jxt check --tag v1.2.0 --strict --release
```

With `--tag`, `jxt check` checks the committed files of a Git tag instead of
the working copy, exported with `git archive` as for `jxt build --tag`. This is
always the final check of that release: the tag must name the manifest version
(`manifest/tag-version`), and asset versions are compared with the highest
tagged version below it. The configuration, including `check_exclude` and
`check_include`, comes from the `.jxt.json` of the tag. Files marked
`export-ignore` in `.gitattributes` are not part of the export, so the
whitespace checks skip them.

A check run on the tag itself, e.g. in CI, needs `--tag`: without it, the
version is already tagged, so `jxt check` runs a pre-check. The CI checkout
needs the earlier tags for the comparison of asset versions (in GitHub Actions
`fetch-depth: 0`). An unknown tag or a project without Git is an invalid call
(exit code 2).

### Annotations in GitHub Actions

```bash
vendor/bin/jxt check --strict --format=github
```

With `--format=github`, `jxt check` prints each finding as a GitHub Actions
workflow command (`::error`, `::warning`, `::notice`), which GitHub shows as an
annotation at the file and line, in the pull request and in the summary of the
run. The title of an annotation is the check name. Findings that concern no
file and invalid calls get an annotation without location; a finding that
covers several lines points to the first one. Paths are relative to the
repository root, also when the project directory is a subdirectory of it.

The first line (version and mode) and the summary remain plain lines, and the
exit codes are the same as for the text output (`--format=text`, the
default). With `--strict`, warnings fail the run but remain warning
annotations.

### Excluding checks

```bash
vendor/bin/jxt check --exclude=editorconfig --include=editorconfig/charset
vendor/bin/jxt check --exclude=all --include=language  # only one group
vendor/bin/jxt config set check_exclude assets/uri,editorconfig/max-line-length
```

`--exclude` and `--include` take `all`, a group (`manifest`) or a single check
(`manifest/stray-files`), several times or comma-separated. In `.jxt.json`,
`check_exclude` and `check_include` do the same permanently for the project.

The command line overrides `.jxt.json`. Within one source, the more specific
name wins (`all` < group < check), so a single check can be included from an
excluded group, and for the same name an include wins over an exclude:

| `.jxt.json` | Command line | Result |
|---|---|---|
| exclude `editorconfig/charset` | – | charset is skipped |
| exclude `editorconfig/charset` | `--include=editorconfig` or `--include=all` | charset runs |
| include `editorconfig/charset` | `--exclude=editorconfig` | charset is skipped |
| – | `--exclude=all --include=editorconfig/charset` | only charset runs |

`check_exclude: ["all"]` in `.jxt.json` turns the include list into the
complete selection; checks added in later jxt versions then do not run in the
project. Unknown names are rejected.

## jxt site – manage the test sites

```bash
vendor/bin/jxt site                         # list the sites, default_site and the selected site
vendor/bin/jxt site add <id> [--default]    # add a site, with --default as default_site
vendor/bin/jxt site remove <id>             # remove a site
vendor/bin/jxt site rename <id> <new id>    # rename a site
```

Sites are only added, removed and renamed with `jxt site`, so that a typo in
`--site` does not add a site; their keys are set with
[jxt config](#jxt-config--show-and-change-the-configuration). `add` creates a
site without keys. `remove` also removes `default_site` if it refers to the
site, `rename` changes it with the site. References outside of the global
configuration, e.g. `JXT_SITE` in an `.envrc` or `--site` in a script, stay
unchanged; jxt points this out, and a forgotten reference fails on the next
call, since an unknown site is an error. The list marks sites without
`joomla_root` and with contradicting keys. Like `jxt config`, `jxt site`
refuses to write under sudo.

```
Id      joomla_root
local   /var/www/html            default_site
example /home/user/sites/example selected via JXT_SITE
j5      –                        joomla_root missing
```

## jxt config – show and change the configuration

```bash
vendor/bin/jxt config                               # show values, origin and the test site
vendor/bin/jxt config set <key> <value> [--global]  # set a value
vendor/bin/jxt config set <key> <value>...          # set a list, also comma-separated
vendor/bin/jxt config set cli_command -- <command>  # set a command, arguments as they are
vendor/bin/jxt config set prepare -- <command>      # set a single build step
vendor/bin/jxt config unset <key> [--global]        # remove a value
```

Without `--global`, `set` and `unset` change the project file `.jxt.json`; with
`--global`, the global file `~/.config/jxt/config.json`. The keys of a test
site (`joomla_root`, `web_user`, `cli_command`, `cli_root`) always change the
selected [test site](#test-sites) in the global file: the one of `--site`,
otherwise of `JXT_SITE`, otherwise of `default_site`. The site must exist,
see [jxt site](#jxt-site--manage-the-test-sites); its id is changed with
`jxt site rename`. `default_site` is set like other global keys, e.g.
`jxt config set default_site example`, and must name an existing site.
`set <key> null` stores
an explicit `null`, e.g. to override a global value in `.jxt.json`; `unset`
deletes the file when its last key is removed. For lists, `set` takes several
values, also comma-separated, and replaces the whole list; `cli_command` keeps
its arguments as they are, without splitting at commas. `set prepare` replaces
the build steps by a single step with `run`, whose arguments are kept the same
way; several steps and steps with `sync` are written into `.jxt.json`
directly. Run
`jxt config` without sudo, otherwise the file would be owned by root; it
refuses to write under sudo.

```
Key           Value                            Origin
manifest      –                                not set
prepare       –                                not set
dist_dir      dist                             default
check_exclude assets/uri                       project
check_include –                                not set
default_site  local                            global
joomla_root   /home/user/sites/example         site
web_user      –                                not set
cli_command   docker exec ddev-example-web php site
cli_root      /var/www/html                    site

Test sites
local, example
current site: example via JXT_SITE

Configuration files
global:  /home/user/.config/jxt/config.json
project: /home/user/projects/mod_example/.jxt.json
```

The table includes the keys of the current site, i.e. of `--site`, `JXT_SITE`
or `default_site`. The origin is one of: project file (`project`), global file
(`global`), the current site (`site`), default value (`default`) or not
set (`not set`). If the keys of the test site contradict each other, e.g.
`web_user` next to `cli_command`, `jxt config`, `jxt config set` and
`jxt config unset` show a warning; `jxt install`, `jxt bridge` and `jxt sync`
via the bridge fail until it is resolved. `jxt config` also warns if no site
is selected, the selected one does not exist or its `joomla_root` is missing.

## Configuration

Two JSON files are merged; values in the project file override the global ones.

| File | Purpose |
|---|---|
| `~/.config/jxt/config.json` | Global settings and the test sites |
| `.jxt.json` in the project directory | Project settings (optional) |

Under sudo, the global file is read from the home directory of the user who
called sudo (`SUDO_USER`), not from `/root`.

| Key | Meaning | Default |
|---|---|---|
| `sites` | The test sites, see [Test sites](#test-sites); only in the global file | – (required for `jxt install`, `jxt sync` and `jxt bridge`) |
| `default_site` | Id of the site used without `--site` and `JXT_SITE`; only in the global file | – (a site must be selected) |
| `manifest` | Manifest file relative to the project directory; only in `.jxt.json` | – (searched in the project directory) |
| `prepare` | Build steps run before `jxt build`, `jxt install`, `jxt check` and `jxt sync`; see [Build steps](#build-steps); only in `.jxt.json` | – |
| `dist_dir` | Output directory of `jxt build` and `jxt install`, relative to the project or absolute | `dist` |
| `check_exclude` | List of checks, groups or `all` that `jxt check` skips; only in `.jxt.json` | – |
| `check_include` | List of checks, groups or `all` that `jxt check` runs despite `check_exclude`; only in `.jxt.json` | – |

All values are strings, lists of strings (`check_exclude`, `check_include`),
`null`, a list of objects for `prepare`, or an object with the sites by id for
`sites`. `null` resets a key to
its default. Unknown keys are rejected, so typos do not go unnoticed.

### Manifest

Usually the project directory is the directory containing the manifest
(`mod_*.xml`, `templateDetails.xml` or the XML file of a plugin or component,
e.g. `example.xml`). The manifest is found automatically: it is the only XML
file in that directory whose root element is `<extension>`.

If the extension lives in a subdirectory of the project (e.g. `extension/`,
with `composer.json`, `tests/` and `docs/` in the project directory), or if the
project directory contains more than one manifest, set the manifest in the
project configuration:

```bash
vendor/bin/jxt config set manifest extension/mod_example.xml
```

As with the Joomla installer, all paths in the manifest are relative to the
directory of the manifest. `.jxt.json` and `dist/` stay in the project
directory.

### Test sites

The test sites are only defined in the global file, since they describe your
machine, not the project, and `cli_command` runs commands. `sites` is an
object with the sites by id; an id starts with a letter and may contain
letters, digits, `_`, `.` and `-`. If an id appears twice, the last site counts,
as usual in JSON; `jxt site add` does not let this happen. Each site is an
object with these keys:

| Key | Meaning | Default |
|---|---|---|
| `joomla_root` | Absolute path of the Joomla site; must contain `configuration.php` | – (required) |
| `web_user` | User as which `jxt install` and the jxt bridge run the Joomla CLI via sudo, e.g. `www-data`; not with `cli_command` | – (the Joomla CLI runs as you, without sudo) |
| `cli_command` | Command that starts PHP for the Joomla CLI, e.g. in a container, instead of sudo; see [Test sites in containers](#test-sites-in-containers) | – |
| `cli_root` | Path of `joomla_root` as `cli_command` sees it; only with `cli_command` | `joomla_root` |

Example `~/.config/jxt/config.json`:

```json
{
    "default_site": "local",
    "sites": {
        "local": { "joomla_root": "/var/www/html", "web_user": "www-data" },
        "leaflet": {
            "joomla_root": "/home/user/ddev/leaflet",
            "cli_command": ["ddev", "exec", "-p", "leaflet", "php"],
            "cli_root": "/var/www/html"
        }
    }
}
```

A site is complete in itself and takes nothing from another site.
`jxt install`, `jxt sync` and `jxt bridge` use the site given with
`--site=<id>`, otherwise the one named in the environment variable
`JXT_SITE`, otherwise `default_site`; an empty `JXT_SITE` counts as unset.
`default_site` is optional: without it, every call needs `--site` or
`JXT_SITE`. A site that does not exist is an error, jxt never falls back to
another site. If the site comes from `--site` or `JXT_SITE`, the output names
it, e.g. `mod_example → /home/user/ddev/leaflet (site "leaflet" via
JXT_SITE)`. `jxt build` and `jxt check` do not use a site.

The site `local` of the example is set up with:

```bash
vendor/bin/jxt site add local --default
vendor/bin/jxt config set joomla_root /var/www/html
vendor/bin/jxt config set web_user www-data
```

For a project that always uses another site, set `JXT_SITE` in its `.envrc`
(see [Calling jxt without vendor/bin](#calling-jxt-without-vendorbin)); a Git
worktree, e.g. for a bugfix of an older version on a site of its own, gets an
`.envrc` of its own:

```bash
PATH_add vendor/bin
export JXT_SITE=leaflet
```

The site cannot be selected in `.jxt.json`: the ids are your own names and do
not belong into a repository.

## Calling jxt without vendor/bin

Composer installs `jxt` into `vendor/bin/` of the project, which is not in the
`PATH`; the examples above therefore call `vendor/bin/jxt`. To type just `jxt`,
[direnv](https://direnv.net) can add `vendor/bin/` to the `PATH` when you enter
the project directory and remove it again when you leave.

Install direnv once and hook it into the shell (Debian/Ubuntu, Bash; for other
shells see the direnv documentation), then open a new shell:

```bash
sudo apt install direnv
echo 'eval "$(direnv hook bash)"' >> ~/.bashrc
```

In each project directory, create an `.envrc` and allow it:

```bash
echo 'PATH_add vendor/bin' > .envrc
direnv allow
```

direnv only loads an `.envrc` after `direnv allow`, and again after every
change to it, so a cloned repository cannot run code or select a
[test site](#test-sites) unasked. `.envrc` is a
personal setting; keep it out of the repository, e.g. in the global Git ignore
file `~/.config/git/ignore`.
