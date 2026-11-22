# Roadmap

Released changes are listed in the [changelog](../CHANGELOG.md).

## Ideas

- Packages (`pkg_`): `jxt build` and `jxt install` support packages. Still
  to come: syncing each extension, and checking the project once and each
  extension with the entries of the package, which Joomla relies on when
  uninstalling. Until then, each extension of a package can be synced and
  checked from its own directory.
- States of the test site: save the database and files of a test site as a
  snapshot with a description, and restore it with one command, e.g. before
  trying an extension or to go back to a known state after a test. A
  snapshot taken automatically before `jxt install` and before each restore
  would make every step reversible. Restoring would replace the tables with
  the site's prefix and the site's files, as the web server user where
  needed. An alternative, or a complement, are several test sites side by
  side that keep evolving, e.g. one per maintained version of an extension;
  jxt can already select them with `--site` and `JXT_SITE`, but not set them
  up. Not decided yet: whether this belongs in jxt or in a tool of its own
  that also defines the test sites, since snapshots do not need an extension
  project.
