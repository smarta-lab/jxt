# jxt

Manifest-driven CLI for Joomla!® extension development:

- **`jxt install`** builds the ZIP file and installs it on a local Joomla test
  server with the Joomla installer, run as the web server user – no backend,
  no root.
- **`jxt sync`** copies a module or template directly to the test server. Only
  the files listed in the manifest are copied, and files that no longer belong
  to the extension are deleted.
- **`jxt build`** packages the extension as a ZIP file that can be installed in
  the Joomla backend.
- **`jxt check`** checks the extension before a release: manifest, whitespace
  according to `.editorconfig`, `CHANGELOG.md`, asset versions and language
  files. With exit codes for CI.
- **`jxt clean --dry-run`** lists files on the test server that no longer
  belong to the extension and that `jxt install` keeps.
- **`jxt config`** shows and changes the global and per-project configuration.

All commands run in the project directory of an extension, like
`vendor/bin/phpunit`. They read the extension's manifest (`mod_*.xml`,
`templateDetails.xml`), so there are no include or exclude lists to maintain.

Supported extension types: modules (site and administrator) and templates.

## Requirements

- PHP 8.4+ with the extensions `libxml`, `simplexml`, `zip` and `posix`
- Linux or WSL; `jxt install` uses `sudo` to run the Joomla CLI as the web
  server user

## Installation

The package is not published on Packagist. Add it to an extension project as a
Composer `path` repository (or as a `vcs` repository pointing to the Git
repository):

```bash
composer config repositories.jxt '{"type": "path", "url": "../jxt"}'
composer require --dev "smarta-lab/jxt:@dev"
```

With a `path` repository, Composer creates a symlink, so changes to jxt take
effect immediately. Add `/dist/` to the project's `.gitignore`.

## Quick start

```bash
# once: tell the tools where the test server is
vendor/bin/jxt config set joomla_root /var/www/html --global

# once: allow running the Joomla installer as www-data without password
# (jxt install prints the exact line for your system if it is missing)
sudo visudo -f /etc/sudoers.d/jxt
#   user ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:install *

# build dist/mod_example-1.0.0.zip and install it on the test server
vendor/bin/jxt install

# only build the ZIP, or build it from a Git tag without uncommitted changes
vendor/bin/jxt build
vendor/bin/jxt build --tag v1.0.0

# check before a release (--release before tagging, --strict in CI)
vendor/bin/jxt check
```

## Documentation

- [Usage](docs/USAGE.md) ([Deutsch](docs/USAGE.de.md)): commands,
  configuration, warnings
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

jxt is not affiliated with or endorsed by the Joomla! Project or Open Source
Matters, Inc. Joomla!® is a registered trademark of Open Source Matters, Inc.
