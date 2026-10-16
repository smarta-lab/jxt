# Roadmap

## Ideas

- Support plugins and components.
- `jxt build --tag v1.1.0` and `jxt install --tag v1.1.0`: build from a Git
  tag (e.g. via `git archive`) so that uncommitted changes do not end up in
  the ZIP.
- `jxt check --format=json`: machine-readable output, e.g. for annotations in
  CI.
- `jxt clean`: remove leftovers that `jxt install` keeps, i.e. files in the
  extension's exclusive directories that no manifest lists (e.g. from earlier
  rsync deployments). Planning works as the developer, since the site is
  world-readable; deleting needs the web server user, see the next item.
- Server-side helper for `jxt sync` and `jxt clean`: a Joomla console plugin
  (`plg_console_jxt`, group `console`, loaded by `cli/joomla.php`) adds
  commands such as `jxt:apply`, run via `sudo -n -u www-data` like
  `jxt install`. jxt plans as the developer and stages the files and a plan
  (copies, deletions) in `/tmp`, because `www-data` cannot read the home
  directory (mode 750); the plugin validates and applies the plan and could
  also refresh `manifest_cache`. It only acts when called via the CLI, so
  there is no web endpoint. The plugin is shipped inside the jxt package
  (`plugin/`, outside the PSR-4 autoload); jxt builds it and installs it via
  the Joomla installer when it is missing or has a different version, so the
  plan format always matches the jxt release. Open: worth it only if
  `jxt install` turns out too slow or leftovers are frequent; needs plugin
  support in jxt.

## Done

- `jxt sync`, `jxt build` and `jxt config`, including `--dry-run`, warnings for
  installer-relevant changes and for files missing from the manifest.
- `jxt install`: real installation via the Joomla CLI as the web server user;
  replaces `sjxt` and the sudo/`chown` setup of `jxt sync`.
- `jxt check`: mechanical pre-release checks of the manifest, whitespace
  according to `.editorconfig` (own parser, compared with the EditorConfig C
  core), `CHANGELOG.md`, asset versions against the last release tag and
  language files, with exit codes for CI. Judging the content (changelog,
  README) stays a manual review.
- `jxt install --zip`: installs an existing ZIP file. An option instead of a
  positional argument, so that an argument stays free for later features
  (e.g. a project directory).
