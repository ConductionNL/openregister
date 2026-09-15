# Tasks: platform-user-migrator

- [ ] 1.1 An `IMigrator` registered for OpenRegister's per-user state (D-1).
- [ ] 1.2 Export of saved views, favourites and recents, watches, notification preferences and overrides.
- [ ] 1.3 Export of personal token metadata with no secret values (D-4).
- [ ] 1.4 Export of the user's own notes and timeline entries as a readable archive.
- [ ] 1.5 A manifest stating what was exported and that objects were not (D-2).
- [ ] 1.6 An import that restores what the target can hold and reports the rest, creating no register or schema (D-3).
- [ ] 2.1 `tests/e2e/ci/platform-user-migrator.spec.ts`: export a user with views and watches, import into a second instance, check both.
- [ ] 2.2 Unit tests: the absence of objects and secrets in the export, the import report for an unrestorable view.
- [ ] 2.3 `openspec validate platform-user-migrator --strict`.
- [ ] 3.1 Tell the dossiq lane that C-access-and-privacy-56, moving a leaver's work to a colleague, is its own act and is not this.
