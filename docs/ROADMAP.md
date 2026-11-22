# Roadmap

Released changes are listed in the [changelog](../CHANGELOG.md).

## Ideas

- Files of a package for `jxt sync`: copy the package manifest, the install
  script and the language files of the package itself, which `jxt sync` only
  compares with the site so far. This needs a bridge that accepts
  `administrator/manifests/packages/`; it is worth it only if packages with
  changing language files of their own turn up. With such a bridge, two
  gaps could be closed as well: `jxt install` could check the script
  directory `administrator/manifests/packages/<packagename>/` for leftovers,
  and `jxt sync` could refresh the manifest cache of the package itself, so
  that the extension manager shows its new version without `jxt install`.
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
