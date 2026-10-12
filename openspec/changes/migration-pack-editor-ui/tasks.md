# Tasks: migration-pack-editor-ui

## Where this change stands

The screens, the draft model, their tests, the translations (English and
Dutch) and this change are written. The API half (envelope body, slug lock,
preview, CSRF, log lines) is built on the same branch; its boxes are ticked
when its PHPUnit tests pass. Translations for the other 35 locales and the
Playwright scenarios are open.

## 1. API

- [ ] 1.1 Create and update read `{"definition": {...}}`, keep the raw body, and let the route id win on update. Test with a real `OC\AppFramework\Http\Request` whose body carries the slug as `id` (D-3).
  - spec_ref: `migration-mapping-packs#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id`
  - files: `lib/Controller/MigrationPacksController.php`, `tests/Unit/Controller/MigrationPacksControllerTest.php`
- [ ] 1.2 Refuse a slug change on a built-in pack with 422 `builtin-slug-locked` (D-4).
  - spec_ref: `migration-mapping-packs#requirement-the-slug-of-a-built-in-pack-must-not-change`
  - files: `lib/Service/MigrationPackService.php`, `lib/Controller/MigrationPacksController.php`, `tests/Unit/Service/MigrationPackServiceTest.php`
- [ ] 1.3 `POST /api/migration-packs/preview`: admin, at most 20 rows, 422 `invalid-pack` with `details.errors`, `MappingEngine::mapRow` per row, nothing stored (D-5).
  - spec_ref: `migration-mapping-packs#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it`
  - files: `appinfo/routes.php`, `lib/Controller/MigrationPacksController.php`, `lib/Service/MigrationPackService.php`
- [ ] 1.4 Remove `NoCSRFRequired` from create, update, destroy and import; preview without it (D-6).
  - spec_ref: `migration-mapping-packs#requirement-migration-pack-writes-must-require-a-csrf-token`
  - files: `lib/Controller/MigrationPacksController.php`
- [ ] 1.5 Info log line with user id and pack slug on update and destroy (D-7).
  - spec_ref: `migration-mapping-packs#requirement-pack-changes-must-be-limited-to-administrators-and-logged`
  - files: `lib/Controller/MigrationPacksController.php`

## 2. Draft model

- [x] 2.1 `packDraft.js`: ordered rows, stored key order, unknown keys kept, three-way lookup default, JSON-typed values, empty `[]` kept (D-2).
  - spec_ref: `migration-mapping-packs#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show`
  - files: `src/views/migrationPack/packDraft.js`
- [x] 2.2 Round-trip tests on the five stackiq TOPdesk packs and the seeded template pack; tests for duplicates, order, the three defaults, unknown keys, validation and summaries.
  - files: `src/views/migrationPack/packDraft.spec.js`, `tests/fixtures/migration-packs/`

## 3. Screens

- [x] 3.1 Migration packs list: name, slug, source format, version, built-in, updated; open, export, delete with confirmation (built-in warning), import JSON.
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `src/views/migrationPack/MigrationPacksIndex.vue`, `src/services/migrationPacks.js`
- [x] 3.2 Editor: general card (slug read-only when built-in), ordered mapping table with add, remove and move, defaults, rows to skip, Advanced (JSON) view, save with `{definition}`, patch version raised, readable 422, refusal when someone else saved first (D-8, D-11).
  - spec_ref: `migration-mapping-packs#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui`
  - files: `src/views/migrationPack/MigrationPackDetail.vue`, `src/components/migrationPack/KeyValueRows.vue`
- [x] 3.3 Transform dialog for every validator type, the three-way choice for other values, unknown keys and types named and kept.
  - files: `src/components/migrationPack/TransformDialog.vue`
- [x] 3.4 Sample-row test over the preview endpoint, 400 ms after the last change, older answers dropped.
  - spec_ref: `migration-mapping-packs#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it`
  - files: `src/components/migrationPack/PackPreviewPanel.vue`
- [x] 3.5 Pages `migration-packs` and `migration-pack-detail` in the manifest and registry; menu entry next to Connections, gated on `user.isAdmin` that `MainMenu.vue` puts in the manifest runtime; pages read-only for a non-admin (D-6, D-10).
  - spec_ref: `migration-mapping-packs#requirement-pack-changes-must-be-limited-to-administrators-and-logged`
  - files: `src/manifest.json`, `src/menu-layout.json`, `src/registry.js`, `src/icons.js`, `src/navigation/MainMenu.vue`
- [x] 3.6 Jest tests for the screens: manifest and menu gating, list load, delete, import, save envelope and version, conflict, 422, JSON view and slug lock, preview request, debounce and ordering.
  - files: `src/views/migrationPack/MigrationPacks.spec.js`

## 4. Translations and docs

- [x] 4.1 English and Dutch for every new string.
  - files: `l10n/en.js`, `l10n/nl.js`
- [ ] 4.2 The other 35 required locales, so `npm run test:l10n:parity` passes.
- [x] 4.3 User documentation of the screens.
  - files: `docs/features/migration-packs.md`

## 5. Verification

- [ ] 5.1 Playwright: an administrator edits a lookup value and saves; a non-admin sees no menu entry and a read-only editor (`tests/e2e/`).
- [ ] 5.2 On a rig with stackiq: the five TOPdesk packs are listed, an edited lookup value shows up in the next CMDB import, and an unedited save leaves the stored definition byte-equal.
