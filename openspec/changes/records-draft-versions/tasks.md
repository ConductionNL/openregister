# Tasks: records-draft-versions

## 1. Draft store

- [ ] 1.1 `ObjectDraft` entity, mapper and migration for `openregister_object_drafts` (key rules from the spec: `main` reserved, lowercase and hyphens). Verify: mapper test on PostgreSQL and MariaDB, and 422 for key `main`.
- [ ] 1.2 `DraftService` create, update (delta only), list, discard, with the spec's visibility rule. Verify: `DraftServiceTest` including a read-only user who cannot see another user's draft.

## 2. Reading and routes

- [ ] 2.1 `version` parameter on `ObjectService::find()` merging the delta and adding `@self._version`. Verify: API test `GET .../{id}?version=update-1` returns merged data, `?version=main` equals no parameter.
- [ ] 2.2 `VersionsController` routes under `/api/objects/{register}/{schema}/{id}/versions` for create, list, read, update and delete. Verify: `tests/Api/ObjectDraftsTest`.
- [ ] 2.3 `_drafts=true` adds visible draft keys to `@self.drafts` without changing the result set. Verify: API test.

## 3. Promotion

- [ ] 3.1 `promote` with per-field conflict detection against `base_version`, 409 body, save through `SaveObject`, draft deleted in the same transaction. Verify: tests for no conflict, conflict, and non-overlapping changes.
- [ ] 3.2 `force=true` for administrators with the overwritten fields on the audit entry. Verify: test that a non-admin gets 403 and an admin's audit entry lists the fields.

## 4. Preview

- [ ] 4.1 `previewUrl` on the schema, validated as a URL template with known placeholders. Verify: schema save test.
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
