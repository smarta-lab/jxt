# jxt

Manifest-driven CLI for Joomla!® extension development:

- **`jxt sync`** deploys a module or template to a local Joomla test server. Only the files listed in
  the manifest are copied, and files that no longer belong to the extension are deleted.
- **`jxt build`** packages the extension as a ZIP file that can be installed in the Joomla backend.
- **`jxt config`** shows and changes the global and per-project configuration.

All commands run in the project directory of an extension, like `vendor/bin/phpunit`.
They read the extension's manifest (`mod_*.xml`, `templateDetails.xml`), so there are no
include or exclude lists to maintain.

Supported extension types: modules (site and administrator) and templates.

## Requirements

- PHP 8.4+ with the extensions `libxml`, `simplexml`, `zip` and `posix`
- Linux or WSL (file ownership is set via `chown`/`chgrp`)

## Installation

The package is not published on Packagist. Add it to an extension project as a Composer
`path` repository (or as a `vcs` repository pointing to the Git repository):

```bash
composer config repositories.jxt '{"type": "path", "url": "../jxt"}'
composer require --dev "smarta-lab/jxt:@dev"
```

With a `path` repository, Composer creates a symlink, so changes to jxt take effect
immediately. Add `/dist/` to the project's `.gitignore`.

## Quick start

```bash
# once: tell the tools where the test server is
vendor/bin/jxt config set joomla_root /var/www/html --global
vendor/bin/jxt config set owner www-data --global
vendor/bin/jxt config set group www-data --global

# build an installable ZIP, e.g. dist/mod_example-1.0.0.zip
vendor/bin/jxt build

# first installation: install the ZIP in the Joomla backend, then deploy changes with
sudo vendor/bin/jxt sync --dry-run
sudo vendor/bin/jxt sync
```

## Documentation

- [Usage](docs/USAGE.md) ([Deutsch](docs/USAGE.de.md)): commands, configuration, warnings
- [Architecture](docs/ARCHITECTURE.md): design decisions and code structure
- [Roadmap](docs/ROADMAP.md): open points and ideas

## Development

```bash
composer install
./vendor/bin/phpunit
./vendor/bin/phpstan analyse
./vendor/bin/phpcs
```

## License

MIT, see [LICENSE](LICENSE).

jxt is not affiliated with or endorsed by the Joomla! Project or Open Source Matters, Inc.
Joomla!® is a registered trademark of Open Source Matters, Inc.
