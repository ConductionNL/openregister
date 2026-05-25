# Tasks — b3a-deprecate-manifest retrofit

Annotations-only. Each task records the `@spec` tag added (or why none was added).

## Annotations added

- [x] task-1 — `deprecate-published-metadata#REQ-6` → annotated
  `lib/Service/Object/SaveObject/MetadataHydrationHandler.php::hydrateObjectMetadata`
  docblock with `@spec openspec/specs/deprecate-published-metadata/spec.md#REQ-6`.
- [x] task-2 — `deprecate-published-metadata#REQ-5` → annotated
  `lib/Db/MultiTenancyTrait.php::applyOrganisationFilter` docblock with
  `@spec openspec/specs/deprecate-published-metadata/spec.md#REQ-5`.
- [x] task-3 — `openregister-app-manifest#MAN-007` → annotated
  `tests/validate-manifest.js` header comment with
  `@spec openspec/changes/openregister-adopt-app-manifest/specs/openregister-app-manifest/spec.md#REQ-OR-MAN-007`.

## No annotation (satisfied-by-absence)

- [x] task-4 — `deprecate-published-metadata#REQ-2` — cleanup complete; no
  `published`/`depublished` keys remain in the copy modals. Nothing to annotate.
- [x] task-5 — `deprecate-published-metadata#REQ-4` — cleanup complete; the
  auto-publish import toggle is gone. Nothing to annotate.

## Gaps (MISSING — reported, not implemented)

- [ ] task-6 — `openregister-app-manifest#MAN-002` — blocked on
  `REQ-OR-MAN-001` (author `src/manifest.json`). No `dependencies: []` to assert
  until the manifest exists.
- [ ] task-7 — `openregister-app-manifest#MAN-008` — blocked on
  `REQ-OR-MAN-001`/`REQ-OR-MAN-005`. No `version` field until the manifest exists.
