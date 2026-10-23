# Roadmap

Released changes are listed in the [changelog](../CHANGELOG.md).

## Ideas

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
- Language check: recognise more keys that are built at runtime, by Joomla
  (e.g. plural forms, messages of list views, fieldset labels) or by the
  extension (a literal followed by `.`), and check site and administrator code
  against the language files of their own area.
- JSON output of `jxt check`, only for a concrete need; `Finding` already has
  file and line as fields.
