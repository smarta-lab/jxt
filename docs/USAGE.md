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

## jxt sync – deploy to the test server

```bash
sudo vendor/bin/jxt sync            # deploy
sudo vendor/bin/jxt sync --dry-run  # only show what would be copied and deleted
```

`vendor/bin/sjxt` is a shorthand for `sudo vendor/bin/jxt` (Linux and macOS).
sudo resets `PATH`, so `sudo jxt` does not find jxt even if `vendor/bin` is in
your `PATH`, e.g. via direnv; `sjxt sync` does.

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
manifest is missing, it stops with an error. Install the extension once in the
Joomla backend with the ZIP file from `jxt build`, then use `jxt sync` for all
further changes.

### Permissions and sudo

Files on the test server usually belong to the web server user (e.g.
`www-data`). Two setups work:

- **With sudo (default):** configure `owner` and `group`; `jxt sync` sets them
  and the modes `dir_mode`/`file_mode` (default 0755/0644).
- **Without sudo:** your user is a member of the web server group and the modes
  allow group write access, e.g. `dir_mode` 0775 and `file_mode` 0664. Leave
  `owner` and `group` unset.

Modes are always applied to the extension's files and directories. Owner and
group are only set when running as root. If `owner` or `group` is configured but
`jxt sync` runs without sudo, it warns about it; if a file operation fails
without sudo, it suggests running it with sudo.

### Warnings and notes

Copying files is not the same as installing via the Joomla installer. `jxt sync`
therefore reports:

- **Changed version:** the extension manager shows the old version until you
  refresh it in "System → Manage → Extensions" with "Refresh Cache".
- **Changed namespace:** `administrator/cache/autoload_psr4.php` is deleted;
  Joomla rebuilds it.
- **Changed installer sections** (`<scriptfile>`, `<install>`, `<uninstall>`,
  `<update>`, `<updateservers>`) or **new/changed install scripts and SQL
  files:** these are only processed by the Joomla installer. Install the ZIP
  from `jxt build` in the backend instead.
- **Files not listed in the manifest:** files in the source directories of
  `<media>`, `<languages>` (and `<files folder="…">`) that the manifest does not
  list, e.g. a new `media/svg/` directory. They would be missing on the server
  and in the ZIP.

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
an explicit `null`, e.g. to disable a global `owner` for one project. Run
`jxt config` without sudo, otherwise the file would be owned by root; it refuses
to write under sudo.

```
Key         Value         Origin
joomla_root /var/www/html global
owner       www-data      global
group       www-data      global
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
| `joomla_root` | Absolute path of the Joomla test server; must contain `configuration.php` | – (required for `jxt sync`) |
| `owner` | Owner of the deployed files, only applied as root | – |
| `group` | Group of the deployed files, only applied as root | – |
| `manifest` | Manifest file relative to the project directory; only in `.jxt.json` | – (searched in the project directory) |
| `dist_dir` | Output directory of `jxt build`, relative to the project or absolute | `dist` |
| `dir_mode` | Mode for directories | `0755` |
| `file_mode` | Mode for files | `0644` |

All values are strings or `null`. Modes are stored with four digits (`775`
becomes `0775`). Unknown keys are rejected, so typos do not go unnoticed.

Example `~/.config/jxt/config.json`:

```json
{
    "joomla_root": "/var/www/html",
    "owner": "www-data",
    "group": "www-data"
}
```
