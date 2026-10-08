# Roadmap

## Ideas

- `jxt build --tag v1.1.0`: build from a Git tag (e.g. via `git archive`) so
  that uncommitted changes do not end up in the ZIP.
- `jxt build`: report "Replaced" instead of "Created" when an existing ZIP is
  overwritten. A `--force` option is not planned: the ZIP can always be
  rebuilt, and repeated builds of the same version are the normal case.
- Support plugins and components.
- `jxt check` (vague idea): mechanical pre-release checks based on the
  manifest jxt already parses, e.g. version consistency between the manifest,
  `CHANGELOG.md` and other files, language files, whitespace (indentation,
  line endings, final newline) and files in the ZIP that do not belong in a
  release. With tests and a non-zero exit code, so it can also run in CI.
  Judging the content (changelog, README) stays a manual review.
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
  also refresh `manifest_cache`. Installed and updated with `jxt install`,
  only acts when called via the CLI, so there is no web endpoint. Open: worth
  it only if `jxt install` turns out too slow or leftovers are frequent; needs
  plugin support in jxt and a versioned plan format shared by jxt and the
  plugin.

## Done

- `jxt sync`, `jxt build` and `jxt config`, including `--dry-run`, warnings for
  installer-relevant changes and for files missing from the manifest.
- `jxt install`: real installation via the Joomla CLI as the web server user;
  replaces `sjxt` and the sudo/`chown` setup of `jxt sync`.
- `jxt install --zip`: installs an existing ZIP file. An option instead of a
  positional argument, so that an argument stays free for later features
  (e.g. a project directory or a version as shorthand).
