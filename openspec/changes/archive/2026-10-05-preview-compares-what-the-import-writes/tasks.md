# Tasks: preview-compares-what-the-import-writes

- [x] 1.1 `previewObjectChange()` drops `@self.version` after the gate and the undeclared top-level `uuid`/`slug` before comparing.
- [x] 2.1 `PreviewHandlerChangesTest`: a seeded object shows only `colour`; a declared slug is still compared; the version is no change. Red on development.
