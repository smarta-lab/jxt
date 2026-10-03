# Roadmap

## Ideas

- Multilingual output: detect the language from `LC_ALL`/`LC_MESSAGES`/`LANG` with English as
  fallback, plus an optional configuration key `language`. Requires exceptions to carry a message
  key with parameters instead of finished German text, so it gets more expensive the longer it waits.
- `jxt build --tag v1.1.0`: build from a Git tag (e.g. via `git archive`) so that uncommitted changes do
  not end up in the ZIP.
- Support plugins and components.
- Move language files into the module directory (`<folder>language</folder>` in `<files>`)? With the
  installed manifest as state this is no longer necessary, but remains an option.

## Done

- `jxt sync`, `jxt build` and `jxt config`, including `--dry-run`, warnings for installer-relevant changes and
  for files missing from the manifest.
