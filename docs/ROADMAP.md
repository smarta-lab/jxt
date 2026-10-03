# Roadmap

## Ideas

- `jxt build --tag v1.1.0`: build from a Git tag (e.g. via `git archive`) so
  that uncommitted changes do not end up in the ZIP.
- `jxt build`: report "Replaced" instead of "Created" when an existing ZIP is
  overwritten. A `--force` option is not planned: the ZIP can always be
  rebuilt, and repeated builds of the same version are the normal case.
- Support plugins and components.

## Done

- `jxt sync`, `jxt build` and `jxt config`, including `--dry-run`, warnings for
  installer-relevant changes and for files missing from the manifest.
