# Tasks: migration-pack-editor-ui

## Where this change stands

The API half (envelope body, slug lock, preview, CSRF, log lines, the one
error shape of D-12, the save precondition of D-8), the screens, the draft
model, their PHPUnit and Jest tests, the translations of the screens' strings
and this change are written on one branch; a box is ticked when its tests
pass. The Playwright file passes on a running instance (5.1); what is open
is 5.2. The tests find the pages by their English names, so `beforeAll`
stops with the `occ` command to run when a test account is set to another
language. CI runs the file only on promotion pull requests and
`workflow_dispatch`.

## 1. API

- [x] 1.1 Create and update read `{"definition": {...}}`, keep the raw body, and let the route id win on update (D-3).
  - spec_ref: `migration-mapping-packs#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id`
  - files: `lib/Controller/MigrationPacksController.php`, `tests/Unit/Controller/MigrationPacksControllerTest.php`
  - acceptance_criteria:
    - GIVEN pack row `{id}` WHEN an administrator sends `PUT /api/migration-packs/{id}` with `{"definition": {"id": "<slug>", ...}}` THEN row `{id}` is updated with HTTP 200 and keeps its slug
    - GIVEN an administrator WHEN they `POST` the pack document itself THEN it is created with HTTP 201
    - `testUpdateReadsTheRouteIdFromARealNextcloudRequest` proves the route id against Nextcloud's own `Request`. It skips without an installed Nextcloud (any local run from a worktree) and runs in the in-container step of `api-test-coverage.yml`, which does not list skipped tests: show it ran with `--filter RealNextcloudRequest --testdox` in that container
- [x] 1.2 Refuse a slug change on a built-in pack with 422 `builtin-slug-locked`, also after the pack was deleted and created or imported again under its slug (D-4).
  - spec_ref: `migration-mapping-packs#requirement-the-slug-of-a-built-in-pack-must-not-change`
  - files: `lib/Service/MigrationPackService.php`, `lib/Controller/MigrationPacksController.php`, `tests/Unit/Service/MigrationPackServiceTest.php`
  - acceptance_criteria:
    - GIVEN the built-in pack `zgw-zaken-json` WHEN an update renames it THEN the answer is 422 `builtin-slug-locked` and the slug is unchanged
    - GIVEN a deleted built-in pack WHEN a JSON file with its slug is imported THEN the stored pack is built-in and its slug locked
- [x] 1.3 `POST /api/migration-packs/preview`: admin, at most 20 rows, `MappingEngine::mapRow` per row, nothing stored (D-5).
  - spec_ref: `migration-mapping-packs#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it`
  - files: `appinfo/routes.php`, `lib/Controller/MigrationPacksController.php`, `lib/Service/MigrationPackService.php`
  - acceptance_criteria:
    - GIVEN a valid definition and up to 20 rows WHEN an administrator previews THEN each row comes back with `rowNumber` from 1, `data` and `errors`
    - 21 rows answer 400 `too-many-rows`; a missing `definition`, `rows` that are not a list of objects, or more than 1 MiB of definition and rows together answer 400 `invalid-request`; an invalid definition answers 422 `invalid-pack` with `details.errors`; a non-admin gets 403
- [x] 1.4 Remove `NoCSRFRequired` from create, update, destroy and import; preview without it (D-6).
  - spec_ref: `migration-mapping-packs#requirement-migration-pack-writes-must-require-a-csrf-token`
  - files: `lib/Controller/MigrationPacksController.php`
  - acceptance_criteria:
    - GIVEN a browser session WHEN a write is sent without the request token THEN Nextcloud refuses it before the controller runs
    - `testWriteMethodsRequireCsrfAndAdmin` covers all five write methods
- [x] 1.5 An info log line on every create, update, import and delete, with the acting user id, the pack slug and the pack row id; the import line says whether it created or replaced a pack; no line on a failed change (D-7).
  - spec_ref: `migration-mapping-packs#requirement-pack-changes-must-be-limited-to-administrators-and-logged`
  - files: `lib/Controller/MigrationPacksController.php`, `tests/Unit/Controller/MigrationPacksControllerTest.php`
  - acceptance_criteria:
    - GIVEN administrator `ronald` WHEN he creates, updates, imports or deletes a pack THEN the log has an info line naming `ronald`, the slug and the row id
    - GIVEN a stored pack WHEN a file with its slug is imported THEN the line says the import replaced it
    - A browser cannot read the Nextcloud log, so these have PHPUnit tests (`testCreateLogsTheActorAndSlug`, `testUpdateLogsTheActorAndSlug`, `testImportReplacingAStoredPackLogsTheActorSlugAndOutcome`, `testImportOfANewSlugLogsThatItCreatedAPack`, `testDestroyActsOnTheRouteIdAndLogsTheActorAndSlug`, and the `...FailureWritesNoAuditLine` tests) and no e2e scenario
- [x] 1.6 One error shape for every refusal of the controller (D-12, ADR-050): `{message, error, details?}`, the message a fixed IL10N sentence and never an exception's text, codes as listed in D-12.
  - spec_ref: `migration-mapping-packs#requirement-migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport`
  - files: `lib/Controller/MigrationPacksController.php`, `lib/Service/MigrationPack/`, `tests/Unit/Controller/MigrationPacksControllerTest.php`
  - acceptance_criteria:
    - GIVEN an invalid definition WHEN it is created, updated, imported or previewed THEN the answer is 422 `invalid-pack` with the findings in `details.errors`, rows numbered from 1
    - GIVEN an update of a row that does not exist THEN the answer is 404 `pack-not-found`
    - GIVEN an unexpected exception THEN the answer is 500 `migration-pack-error` without its text, and the exception is logged
    - Proven by `testEveryWriteAnswersAnInvalidPackInTheSharedShape`, `testAnUnknownRowIdAnswersPackNotFound`, `testAnUnexpectedFailureIsLoggedAndAnsweredWithoutItsText` and `testARefusedCallerGetsAMessageAndACode`
- [x] 1.7 An update envelope may name the `updatedAt` it was based on (`expectedUpdatedAt`); the server refuses the update with 409 `pack-changed` when the pack was saved since, and the editor sends it on every save (D-8).
  - spec_ref: `migration-mapping-packs#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id`
  - files: `lib/Service/MigrationPackService.php`, `lib/Service/MigrationPack/MigrationPackRequestReader.php`, `lib/Service/MigrationPack/MigrationPackErrorResponder.php`, `src/services/migrationPacks.js`, `src/views/migrationPack/MigrationPackDetail.vue`
  - acceptance_criteria:
    - GIVEN a stored pack WHEN an update names an `expectedUpdatedAt` the pack no longer has THEN the answer is 409 `pack-changed` and the pack is unchanged (`testUpdateRefusesAPackSavedAfterTheCallerReadIt`, `testUpdateBasedOnAnOutdatedReadAnswersPackChanged`)
    - GIVEN an `expectedUpdatedAt` that is not a date-time as the API answers `updatedAt` THEN the answer is 400 `invalid-request`
    - GIVEN the editor WHEN it saves THEN the update names the `updatedAt` it loaded, and a 409 `pack-changed` is shown with a reload

## 2. Draft model

- [x] 2.1 `packDraft.js`: ordered rows, stored key order, unknown keys kept, three-way lookup default, JSON-typed values, empty `[]` kept (D-2).
  - spec_ref: `migration-mapping-packs#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show`
  - files: `src/views/migrationPack/packDraft.js`
  - acceptance_criteria:
    - GIVEN a stored definition WHEN the draft is built and turned back into a payload THEN it equals the stored one, key for key and in order
- [x] 2.2 Round-trip tests on the five stackiq TOPdesk packs and the seeded template pack; tests for duplicates, order, the three defaults, unknown keys, validation and summaries.
  - spec_ref: `migration-mapping-packs#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show`
  - files: `src/views/migrationPack/packDraft.spec.js`, `tests/fixtures/migration-packs/`
  - acceptance_criteria:
    - GIVEN each of the five stackiq packs (four of which stackiq stores, see D-2) and `zgw-zaken-json` WHEN loaded and saved without an edit THEN each comes back equal and in the same key order
    - GIVEN the module pack WHEN saved THEN the `"default": null` of its `cloudDienstverleningsmodel` lookup is still `null`, and both `Applicatiesoort` rows are kept in order

## 3. Screens

- [x] 3.1 Migration packs list: name, slug, source format, version, built-in, updated; open, export, delete with confirmation (built-in warning), import JSON.
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `src/views/migrationPack/MigrationPacksIndex.vue`, `src/dialogs/migrationPack/DeleteMigrationPackDialog.vue`, `src/services/migrationPacks.js`
  - acceptance_criteria:
    - GIVEN the built-in pack WHEN an administrator chooses Delete THEN the confirmation says the app installs it again, unedited
    - GIVEN a JSON file WHEN an administrator imports it THEN the page says so and lists the pack
- [x] 3.2 Editor: general card (slug read-only when built-in), ordered mapping table with add, remove and move, defaults, rows to skip, Advanced (JSON) view, save with `{definition}`, patch version raised, readable 422, refusal when someone else saved first (D-8, D-11).
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `src/views/migrationPack/MigrationPackDetail.vue`, `src/components/migrationPack/KeyValueRows.vue`
  - acceptance_criteria:
    - GIVEN the usage pack at `1.0.0` WHEN a lookup value is changed and saved THEN the editor says first that the version goes to `1.0.1`, then that it saved `1.0.1`
    - GIVEN a pack someone else saved after it was opened WHEN the administrator saves THEN nothing is sent and a reload is offered
- [x] 3.3 Transform dialog for every validator type, the three-way choice for other values, unknown keys and types named and kept.
  - spec_ref: `migration-mapping-packs#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show`
  - files: `src/dialogs/migrationPack/TransformDialog.vue`
  - acceptance_criteria:
    - GIVEN a transform with an option the editor does not show WHEN its dialog opens THEN the dialog names the option and says it is kept
- [x] 3.4 Sample-row test over the preview endpoint, 400 ms after the last change, older answers dropped.
  - spec_ref: `migration-mapping-packs#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it`
  - files: `src/components/migrationPack/PackPreviewPanel.vue`
  - acceptance_criteria:
    - GIVEN an unsaved edit WHEN a sample row is tested THEN the result follows the edit
    - GIVEN a required column left empty THEN the row's problem names its source and target
- [x] 3.5 Pages `migration-packs` and `migration-pack-detail` in the manifest and registry; menu entry next to Connections, gated on `user.isAdmin` that `MainMenu.vue` puts in the manifest runtime; pages read-only for a non-admin (D-6, D-10).
  - spec_ref: `migration-mapping-packs#requirement-pack-changes-must-be-limited-to-administrators-and-logged`
  - files: `src/manifest.json`, `src/menu-layout.json`, `src/registry.js`, `src/icons.js`, `src/navigation/MainMenu.vue`
  - acceptance_criteria:
    - GIVEN a non-admin WHEN they open OpenRegister THEN there is no Migration packs entry; an administrator has one
    - GIVEN a non-admin WHEN they open a pack by URL THEN every field is disabled and there is no Save, Import, Delete or sample-row test
- [x] 3.6 Jest tests for the screens: manifest and menu gating, list load, delete, import, save envelope and version, conflict, 422, JSON view and slug lock, preview request, debounce and ordering.
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `src/views/migrationPack/MigrationPacks.spec.js`
  - acceptance_criteria:
    - `npm test -- src/views/migrationPack` passes
- [x] 3.7 The screens read the error code of D-12, show their own translated text for it, list `details.errors` one per line, and never parse an English server message.
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `src/services/migrationPacks.js`, `src/views/migrationPack/`, `src/components/migrationPack/PackPreviewPanel.vue`
  - acceptance_criteria:
    - GIVEN a save answered 422 `invalid-pack` with two findings THEN the editor shows each finding on its own line and keeps the unsaved changes
    - Proven by the Jest cases "read the slug, never the wording, of an error" and "shows each finding of a refused save with its table row number"

## 4. Translations and docs

- [x] 4.1 English and Dutch for every new string.
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `l10n/en.js`, `l10n/nl.js`, `l10n/nl.json` (the manifest titles and menu label, which the manifest l10n gate reads there, and the controller's IL10N messages)
  - acceptance_criteria:
    - `npm run test:l10n` reports no missing key from these screens
- [x] 4.2 The screens' strings in the other required locales, so `npm run test:l10n:parity` passes. The controller's IL10N messages are in `nl.json` only, as for the rest of the backend set; any other locale shows them in English.
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `l10n/*.js`
  - acceptance_criteria:
    - `npm run test:l10n:parity` prints OK
- [x] 4.3 User documentation of the screens.
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `docs/features/migration-packs.md`
  - acceptance_criteria:
    - The page says who can do what, how to change, test and save a pack, what a built-in pack is, and where the log lines go

## 5. Verification

- [x] 5.1 Playwright: every scenario of the delta spec, anchored with `@e2e migration-mapping-packs::<scenario>` (`tests/e2e/ci/migration-pack-pages.spec.ts`). 38 of 38 tests passed on 2026-10-13 against Nextcloud 35.0.1 with this branch (8ef92f0c27) built and installed.
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `tests/e2e/ci/migration-pack-pages.spec.ts`
  - acceptance_criteria:
    - An administrator edits a lookup value and saves; deletes after confirming; imports a JSON file; tests a sample row
    - A non-admin sees no menu entry, a read-only list and a read-only editor, and is refused 403 by the API
    - A browser write without the request token is refused; the editor's own save carries it
- [ ] 5.2 On a rig with stackiq: the four packs stackiq stores (module, manufacturer, usage, business owner; the municipality pack stays in stackiq's files, see D-2) are listed, an edited lookup value shows up in the next CMDB import, and an unedited save leaves the stored definition byte-equal. Open: the import half has not been run.
  - spec_ref: `migration-mapping-packs#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show`
  - acceptance_criteria:
    - GIVEN a rig with stackiq WHEN the Migration packs page opens THEN the four stackiq packs are listed as built-in
    - GIVEN an edited lookup value WHEN the CMDB import runs THEN the imported object carries the edited value
