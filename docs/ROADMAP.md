# Roadmap

Released changes are listed in the [changelog](../CHANGELOG.md).

## Ideas

- Packages (`pkg_`): decide how jxt handles them, before 1.0. A package has a
  manifest of its own and one per extension, often in one repository; this
  does not fit the key `manifest`, which names a single file. `jxt build`
  would have to build the ZIPs of the extensions and the package ZIP, and
  `jxt sync` would have to deploy each extension. Until then, each extension
  of a package can be built, installed and synced from its own directory.

- Language check: recognise more keys that are built at runtime, by Joomla
  (e.g. plural forms, messages of list views, fieldset labels) or by the
  extension (a literal followed by `.`), and check site and administrator code
  against the language files of their own area.
