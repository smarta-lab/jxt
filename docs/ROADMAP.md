# Roadmap

## Ideas

- `jxt build --tag v1.1.0`: build from a Git tag (e.g. via `git archive`) so that uncommitted changes do
  not end up in the ZIP.
- Support plugins and components.

## Done

- `jxt sync`, `jxt build` and `jxt config`, including `--dry-run`, warnings for installer-relevant changes and
  for files missing from the manifest.
