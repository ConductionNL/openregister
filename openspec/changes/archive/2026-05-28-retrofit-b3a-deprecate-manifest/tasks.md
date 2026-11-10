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

## Gaps unblocked (MAN-001 satisfied by openregister-manifest-shell-swap)

- [x] task-6 — `openregister-app-manifest#MAN-002` → annotated
  `tests/validate-manifest.js` header docblock with
  `@spec openspec/changes/openregister-adopt-app-manifest/specs/openregister-app-manifest/spec.md#REQ-OR-MAN-002`.
  Manifest now ships `"dependencies": []` (OR is the foundation app); validator
  schema-asserts the field on every CI run.
- [x] task-7 — `openregister-app-manifest#MAN-008` → annotated
  `tests/validate-manifest.js` header docblock with
  `@spec openspec/changes/openregister-adopt-app-manifest/specs/openregister-app-manifest/spec.md#REQ-OR-MAN-008`.
  Manifest now ships `"version": "1.0.0"` (Tier-4 / full-shell adoption);
  validator schema-asserts the field plus structural-lint fallback.
