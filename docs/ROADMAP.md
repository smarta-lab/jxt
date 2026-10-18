# Roadmap

## Ideas

- Support plugins and components.
- JSON output of `jxt check`, only for a concrete need; `Finding` already has
  file and line as fields.
- `jxt install --clean`: delete the leftovers that `jxt install` lists.
  Planning works as the developer, since the site is world-readable; deleting
  needs the web server user, see the next item.
- Server-side helper for `jxt sync` and `jxt install --clean`: a Joomla console
  plugin (`plg_console_jxt`, group `console`, loaded by `cli/joomla.php`) adds
  commands such as `jxt:apply`, run via `sudo -n -u www-data` like
  `jxt install`. jxt plans as the developer and stages the files and a plan
  (copies, deletions) in `/tmp`, because `www-data` cannot read the home
  directory (mode 750); the plugin validates and applies the plan and could also
  refresh `manifest_cache`. It only acts when called via the CLI, so there is no
  web endpoint. The plugin is shipped inside the jxt package (`plugin/`, outside
  the PSR-4 autoload); jxt builds it and installs it via the Joomla installer
  when it is missing or has a different version, so the plan format always
  matches the jxt release. Open: worth it only if `jxt install` turns out too
  slow or leftovers are frequent; needs plugin support in jxt.

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
- Leftover report of `jxt install`: after a successful installation, it lists
  the entries in the extension's exclusive directories that the installed ZIP
  does not contain. Replaces the unreleased `jxt clean --dry-run`, which
  compared against the working copy and could not know which state was
  installed.
- `jxt build --tag` and `jxt install --tag`: build the ZIP from the committed
  files of a Git tag (`git archive`), so that uncommitted changes do not end up
  in a release ZIP.
- `jxt check --tag`: checks the files of a tag as the final check of that
  release, including that the tag names the manifest version; needed in CI on
  tags, where the version is already tagged.
- `jxt check --format=github`: findings as annotations in GitHub Actions
  (workflow commands, as PHPStan's `--error-format=github`).
- `jxt install --zip`: installs an existing ZIP file. An option instead of a
  positional argument, so that an argument stays free for later features
  (e.g. a project directory).
