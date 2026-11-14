# Tasks: platform-user-migrator

- [x] 1.1 An `IMigrator` registered for OpenRegister's per-user state (D-1).
  - `Service/UserMigration/PersonalStateMigrator`, registered in `Application::register()` with `registerUserMigrator()`.
- [ ] 1.2 Export of saved views, favourites and recents, watches, notification preferences and overrides.
  - Partly built: the views the user owns and the objects they watch (a favourite is a watch here, `_favourite` is the deprecated alias of `_watching`), register and schema references as slugs. Left: recents, notification preferences and overrides.
- [x] 1.3 Export of personal token metadata with no secret values (D-4).
  - The user's calendar feed tokens (`openregister/feed-tokens.json`): label, scope (a schema scope as its slug), created, expires, revoked and last read; never the token value. An import creates no token and names each one as not restored.
- [x] 1.4 Export of the user's own notes and timeline entries as a readable archive.
  - `openregister/notes.json` (the user's own comments of the OpenRegister notes type) and `openregister/timeline.json` (entries they authored, register and schema as slugs). An import restores neither and says so in one line.
- [x] 1.5 A manifest stating what was exported and that objects were not (D-2).
  - `openregister/manifest.json`: counts, `objectsIncluded: false`, `secretsIncluded: false`.
- [x] 1.6 An import that restores what the target can hold and reports the rest, creating no register or schema (D-3).
  - Views and watches whose registers and schemas resolve on the target by slug are restored (views private, owned by the importing user); the rest is named in the report on the command's output. Items added under 1.2 to 1.4 join the same report.
- [ ] 2.1 `tests/e2e/ci/platform-user-migrator.spec.ts`: export a user with views and watches, import into a second instance, check both.
- [x] 2.2 Unit tests: the absence of objects and secrets in the export, the import report for an unrestorable view.
  - `tests/Unit/Service/UserMigration/` (PersonalStateMigratorTest, RegisterReferencesTest, PersonalStateReaderTest).
- [ ] 2.3 `openspec validate platform-user-migrator --strict`.
- [ ] 3.1 Tell the dossiq lane that C-access-and-privacy-56, moving a leaver's work to a colleague, is its own act and is not this.
