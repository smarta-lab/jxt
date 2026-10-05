# Usage

[Deutsch](USAGE.de.md)

All commands are run in the project directory of an extension. Usually this is
the directory containing the manifest (`mod_*.xml` or `templateDetails.xml`).
The manifest is found automatically: it is the only XML file in that directory
whose root element is `<extension>`.

If the extension lives in a subdirectory of the project (e.g. `extension/`, with
`composer.json`, `tests/` and `docs/` in the project directory), or if the
project directory contains more than one manifest, set the manifest in the
project configuration:

```bash
vendor/bin/jxt config set manifest extension/mod_example.xml
```

As with the Joomla installer, all paths in the manifest are relative to the
directory of the manifest. `.jxt.json` and `dist/` stay in the project
directory.

## jxt install – install on the test server

```bash
vendor/bin/jxt install
```

`jxt install` builds the ZIP file like `jxt build` and installs it with the
Joomla installer, without opening the backend in a browser:

1. The ZIP file is copied to `/tmp` with mode 0644, so the web server user can
   read it without access to the project.
2. The Joomla CLI installs it as the web server user:
   `sudo -n -u www-data php <joomla_root>/cli/joomla.php extension:install --path=…`
3. The copy in `/tmp` is deleted.

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
the old manifest and are missing from the new one. Leftovers that are not listed
in any manifest remain.

For an update, the manifest needs `method="upgrade"`; otherwise Joomla refuses
to install over an existing extension. If the installation fails and the
attribute is missing, `jxt install` points this out.

### Setup: sudo without password for the Joomla installer

So that the files belong to the web server user, the Joomla CLI runs as that
user (`web_user`, default `www-data`). `sudo -n` never asks for a password; a
sudoers rule is needed once:

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

If PHP on the test server already runs as your own user (e.g. in a container),
`jxt config set web_user null` disables sudo.

## jxt sync – copy files directly

```bash
vendor/bin/jxt sync            # deploy
vendor/bin/jxt sync --dry-run  # only show what would be copied and deleted
```

`jxt sync` copies the files without the Joomla installer. This is faster and
also cleans up leftovers, but requires that your user may write to the target
directories (see [Permissions](#permissions)). If the files belong to the web
server user, use `jxt install`.

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

### What is copied

Exactly what the manifest lists, mapped the same way the Joomla installer does:

| Manifest section | Target on the server (module, site) |
|---|---|
| the manifest itself, `<files>`, `<scriptfile>` | `modules/<element>/` |
| `<languages>` | `language/<tag>/` |
| `<media destination="…">` | `media/<destination>/` |

Administrator modules go to `administrator/modules/` and
`administrator/language/`, templates to `templates/<name>/`. Files are compared
by size and content; unchanged files are not copied. Names starting with `.git`
(e.g. `.gitkeep`) are skipped.

### What is deleted

The manifest installed on the server describes the last deployed state.

- In the directories owned by the extension (`modules/<element>/`,
  `media/<destination>/`), everything that is not part of the new manifest is
  deleted, including whole directories that were removed from the manifest.
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

`jxt sync` runs with the permissions of your user. The modes
`dir_mode`/`file_mode` (default 0755/0644) are applied to the extension's files
and directories and to newly created directories; existing shared directories
such as `language/de-DE/` are left unchanged. Only the owner of a file may run
`chmod` on it; group permissions are not enough.

If a file operation fails, `jxt sync` points to `jxt install`.

### Warnings and notes

Copying files is not the same as installing via the Joomla installer. `jxt sync`
therefore reports:

- **Changed version:** the extension manager shows the old version until you run
  `jxt install` or refresh it in "System → Manage → Extensions" with "Refresh
  Cache".
- **Changed namespace:** `administrator/cache/autoload_psr4.php` is deleted;
  Joomla rebuilds it.
- **Changed installer sections** (`<scriptfile>`, `<install>`, `<uninstall>`,
  `<update>`, `<updateservers>`) or **new/changed install scripts and SQL
  files:** these are only processed by the Joomla installer. Run `jxt install`
  instead.
- **Files not listed in the manifest:** files in the source directories of
  `<media>`, `<languages>` (and `<files folder="…">`) that the manifest does not
  list, e.g. a new `media/svg/` directory. They would be missing on the server
  and in the ZIP. `jxt install` and `jxt build` show this warning as well.

Default values of new module parameters are not written to existing module
instances; this is also true for a real upgrade, so always read parameters with
a default: `$params->get('x', default)`.

## jxt build – build an installable ZIP

```bash
vendor/bin/jxt build
```

Creates `dist/<element>-<version>.zip` (templates:
`dist/tpl_<name>-<version>.zip`) containing the manifest and all files it lists,
with their paths relative to the manifest. An existing file with the same name
is replaced. Like `jxt sync`, `jxt build` warns about files that are not listed
in the manifest.

The ZIP is built from the working copy, including uncommitted changes.

## jxt config – show and change the configuration

```bash
vendor/bin/jxt config                               # show values and origin
vendor/bin/jxt config set <key> <value> [--global]  # set a value
vendor/bin/jxt config unset <key> [--global]        # remove a value
```

Without `--global`, `set` and `unset` change the project file `.jxt.json`; with
`--global`, the global file `~/.config/jxt/config.json`. `set <key> null` stores
an explicit `null`. Run `jxt config` without sudo, otherwise the file would be
owned by root; it refuses to write under sudo.

```
Key         Value         Origin
joomla_root /var/www/html global
web_user    www-data      default
manifest    –             not set
dist_dir    dist          default
dir_mode    0775          project
file_mode   0644          default
```

The origin is one of: project file (`project`), global file (`global`), default
value (`default`) or not set (`not set`).

## Configuration

Two JSON files are merged; values in the project file override the global ones.

| File | Purpose |
|---|---|
| `~/.config/jxt/config.json` | Global settings, e.g. the test server |
| `.jxt.json` in the project directory | Project-specific overrides (optional) |

Under sudo, the global file is read from the home directory of the user who
called sudo (`SUDO_USER`), not from `/root`.

| Key | Meaning | Default |
|---|---|---|
| `joomla_root` | Absolute path of the Joomla test server; must contain `configuration.php` | – (required for `jxt install` and `jxt sync`) |
| `web_user` | User as which `jxt install` runs the Joomla CLI via sudo; `null` to run it without sudo | `www-data` |
| `manifest` | Manifest file relative to the project directory; only in `.jxt.json` | – (searched in the project directory) |
| `dist_dir` | Output directory of `jxt build` and `jxt install`, relative to the project or absolute | `dist` |
| `dir_mode` | Mode for directories in `jxt sync` | `0755` |
| `file_mode` | Mode for files in `jxt sync` | `0644` |

All values are strings or `null`. `null` resets a key to its default; only for
`web_user` it means "without sudo". Modes are stored with four digits (`775`
becomes `0775`). Unknown keys are rejected, so typos do not go unnoticed.

Example `~/.config/jxt/config.json`:

```json
{
    "joomla_root": "/var/www/html"
}
```
