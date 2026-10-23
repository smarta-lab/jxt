# Roadmap

Released changes are listed in the [changelog](../CHANGELOG.md).

## Ideas

- Build steps: a configurable hook (a list of commands run in the project
  directory before `jxt build`, `jxt install` and `jxt sync`) for projects
  that generate files first, e.g. CSS from SCSS or a manifest with the version
  filled in. Projects whose own build pipeline produces the ZIP can already
  install it with `jxt install --zip`.
- Language check: recognise more keys that are built at runtime, by Joomla
  (e.g. plural forms, messages of list views, fieldset labels) or by the
  extension (a literal followed by `.`), and check site and administrator code
  against the language files of their own area.
