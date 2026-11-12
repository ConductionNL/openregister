# Tasks: example-set-typed-app-config

- [x] Catalogue reads and checks `type` (string, int); `ExampleSetAppConfig` writes, reads, proves and restores by type; the record entry keeps the type
- [x] Test (failing first, with the harness refusing a typed key as text, as Nextcloud does): an int entry written, kept and restored; a non-integer refused
