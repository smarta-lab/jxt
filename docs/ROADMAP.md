# Roadmap

## Ideas

- JSON output of `jxt check`, only for a concrete need; `Finding` already has
  file and line as fields.
- Test sites in containers (DDEV, Docker): `jxt install` runs the Joomla CLI
  with the PHP of the host, which may lack the right version or extensions and
  cannot resolve a database host such as `db` that only exists between
  containers. An execution prefix (e.g. `docker exec -u www-data <container>`)
  would run the CLI inside the container, as `sudo -n -u <web_user>` does for
  another user. Open: the path of the site inside the container besides
  `joomla_root`, a place for the ZIP that the container can read (the host's
  `/tmp` is not visible there), and whether `web_user` becomes a special case
  of the prefix. The jxt bridge runs the Joomla CLI and stages its files in
  `/tmp` as well, so it needs the same. Until then, `jxt sync` works on such
  sites without the bridge, since the files are usually shared with the host.
- Build steps: a configurable hook (a list of commands run in the project
  directory before `jxt build`, `jxt install` and `jxt sync`) for projects
  that generate files first, e.g. CSS from SCSS or a manifest with the version
  filled in. Projects whose own build pipeline produces the ZIP can already
  install it with `jxt install --zip`.

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
- Plugins: install directory `plugins/<group>/<element>/`, language files in
  `administrator/language/`, ZIP name and prefix of the language constants
  `plg_<group>_<element>`.
- jxt bridge (`jxt bridge add|remove|status`): a Joomla console plugin shipped
  in `plugin/` that applies the plans of `jxt sync` as web server user
  (`jxt:apply`), so that `jxt sync` also works on sites whose files belong to
  the web server user; jxt builds it with its own plugin support and updates
  it when the bundled version differs.
- `jxt install --clean`: deletes the leftovers that `jxt install` lists, via
  the jxt bridge or directly, like the deletions of `jxt sync`.
- Components: three install directories (`administrator/components/`,
  `components/`, `api/components/`) with their own `<files>` sections, the
  manifest, install script and SQL files in the administrator directory;
  changed menu items count as installer sections for `jxt sync`.
- `jxt install --zip`: installs an existing ZIP file. An option instead of a
  positional argument, so that an argument stays free for later features
  (e.g. a project directory).
