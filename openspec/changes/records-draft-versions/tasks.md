# Tasks: records-draft-versions

## 1. Draft store

- [ ] 1.1 `ObjectDraft` entity, mapper and migration for `openregister_object_drafts` (key rules from the spec: `main` reserved, lowercase and hyphens). Verify: mapper test on PostgreSQL and MariaDB, and 422 for key `main`.
- [x] 1.2 `DraftService` create, update (delta only), list, discard, with the spec's visibility rule. Verify: `DraftServiceTest` including a read-only user who cannot see another user's draft.

## 2. Reading and routes

- [x] 2.1 `version` parameter on `ObjectService::find()` merging the delta and adding `@self._version`. Verify: API test `GET .../{id}?version=update-1` returns merged data, `?version=main` equals no parameter.
      > Built at the read route rather than in `ObjectService::find()`, whose signature fleet apps call with named arguments (published contract): `ObjectsController::show()` reads `version`; `main` or absent is the live object unchanged; another key goes through `DraftService::find()` (creator or write access), merges the delta onto a copy of the live object, renders it through the same path and stamps `@self._version` `{key, name, base}`; an absent or invisible draft answers 404 like a missing object (`lib/Controller/Support/DraftVersionRead.php`). Unit: `DraftVersionReadTest`, `ObjectsControllerDraftVersionTest`. The API test needs an instance and is not written.
- [x] 2.2 `VersionsController` routes under `/api/objects/{register}/{schema}/{id}/versions` for create, list, read, update and delete. Verify: `tests/Api/ObjectDraftsTest`.
- [x] 2.3 `_drafts=true` adds visible draft keys to `@self.drafts` without changing the result set. Verify: API test.
      > Both list paths of `ObjectsController::index()` (magic table and object service) pass their rendered rows through `Controller/Support/DraftKeysOnList`: one `findForObjects` read for the page, own drafts always shown, others only after `update` on the loaded object (a failed load hides them); rows are never added, removed or reordered. Unit: `DraftKeysOnListTest`, `ObjectsControllerDraftKeysTest`, `DraftServiceTest`. The API test needs an instance and is not written.

## 3. Promotion

- [x] 3.1 `promote` with per-field conflict detection against `base_version`, 409 body, save through `SaveObject`, draft deleted in the same transaction. Verify: tests for no conflict, conflict, and non-overlapping changes.
      > `Service/Object/DraftPromoter` behind `POST .../versions/{key}/promote` (`VersionsController::promote`): the base state comes from the audit trail (`revertObjectWith` to `base_version`; an unrebuildable base counts every differing field as a conflict), 409 carries `conflicts: [{field, draft, live}]`, the merged data is saved through `ObjectOperationsInterface::saveObject()`, and the `draft.promoted` audit entry and the draft delete run in one transaction (rolled back on a refused save). Unit: `DraftPromoterTest`, `VersionsControllerTest`.
- [x] 3.2 `force=true` for administrators with the overwritten fields on the audit entry. Verify: test that a non-admin gets 403 and an admin's audit entry lists the fields.
      > `force=true` from a non-administrator is 403 before anything is read; an administrator's promotion records `forced: true` and `overwritten: [fields]` (with the previous values) on the `draft.promoted` entry. Unit: `DraftPromoterTest`.

## 4. Preview

- [x] 4.1 `previewUrl` on the schema, validated as a URL template with known placeholders. Verify: schema save test.
      > Declared as `configuration.previewUrl`, checked by `Db/Schema/PreviewUrlTemplate` from `ConfigurationEntryRules`: an absolute http(s) URL using only `{uuid}`, `{version}`, `{token}`; a bad template is dropped and reported like any refused key, the rest of the configuration is kept. `Schema::getPreviewUrl()` reads it back. Unit: `PreviewUrlTemplateTest` (schema save included).
- [ ] 4.2 Access link subject type `object-draft`, read only, expiry required; public route serves the merged draft. Verify: API test that the link reads the draft, and 404 after expiry or revocation.

## 5. Interface

- [ ] 5.1 Drafts tab on `ObjectDetails.vue`: list, edit, compare, preview, promote, discard. Verify: `tests/e2e/record-drafts.spec.ts` creates a draft, checks the published object is unchanged, promotes it and sees the change.
- [ ] 5.2 Preview button opening the filled `previewUrl`. Verify: same e2e asserts the opened URL carries the token.

## 6. Spec and docs

- [ ] 6.1 Remove the "Status: deferred" notes from the two draft requirements in `openspec/specs/content-versioning/spec.md` when 1.1 to 3.2 have shipped.
- [ ] 6.2 `docs/` page on drafts, promotion and site preview.

Acceptance:
- An object with no drafts reads, lists and saves exactly as before.
- No list, count, facet or search returns a draft as an object.
