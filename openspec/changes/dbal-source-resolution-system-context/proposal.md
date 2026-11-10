# Proposal: dbal-source-resolution-system-context

## Why

Follow-up to openregister#2084 / PR #2088. That PR fixed a real `orX()` zero-arg
defect in `MultiTenancyTrait::applyActiveOrgFilter()`, but live-verification on
the shared dev instance under `saasMode: true` showed dbal-source (query-backed)
schemas still returned `total: 0` — a second, independent defect, filed as
openregister#2089.

`DbalObjectSourceProvider::resolveSource()` loaded the backing `Source` entity
via `SourceMapper::find()` / `SourceMapper::findAll()`, both of which apply
`MultiTenancyTrait::applyOrganisationFilter()` — the same tenant filter used for
ordinary tenant-owned rows. A `Source` is shared infrastructure config (an
external database connection), not tenant-owned data: it is the *provider* a
dbal-backed schema reads through, not an object that itself belongs to one
tenant's data set. When the Source's own `organisation` column differs from the
querying admin's active organisation, and `saasMode: true` unconditionally
disables admin override (`MultiTenancyTrait::isAdminOverrideEnabled()`), the
Source row is filtered out of the query entirely — `resolveSource()` returns
`null`, and every downstream `find`/`findAll`/`count`/`aggregate` on that schema
silently returns empty, logged only as a `warning`, never surfaced to the
caller.

## What Changes

- Add `SourceMapper::findForSystem(string $sourceId): ?Source` — a lookup by id
  or uuid that intentionally skips both RBAC verification and the
  organisation filter, mirroring the existing `findBySyncEnabled()` system-actor
  pattern already established in the same mapper. Documented with an explicit
  security rationale: this method must never be exposed to a caller that has
  not already authorized the request at the schema/object level, and must never
  be used to serve Source data (credentials, connection config) directly to a
  client.
- `DbalObjectSourceProvider::resolveSource()` now calls
  `SourceMapper::findForSystem()` instead of `find()`/`findAll()`.

## Why this is safe

Resolving a Source referenced by a schema's `x-openregister-object-source.config.sourceId`
is a SYSTEM capability lookup, not a user-facing read of the Source itself.
`ObjectService::paginateObjectSource()` already calls `checkPermission(schema:
…, action: 'read', …)` — the schema's own read RBAC — **before** the provider
(and therefore `resolveSource()`) ever runs. A caller who fails that check never
reaches `resolveSource()` at all. Tenant-filtering the Source row on top of that
adds no additional isolation (the caller never sees the Source row — only the
objects it serves for a schema they were already cleared to read) while
breaking any dbal-backed schema whose Source is configured in a different
organisation than the reader's active one — exactly the cross-org shared
registers use case this issue was raised against.

Default org filtering for every other `SourceMapper` caller (the Sources admin
UI, CRUD, `find()`/`findAll()` as used by controllers) is completely
unchanged — only the internal system lookup gets the new, narrowly-scoped
method.

## Impact

- **Affected code**: `lib/Db/SourceMapper.php` (new method),
  `lib/Service/ObjectSource/DbalObjectSourceProvider.php` (`resolveSource()`).
- **Tests**: `tests/Unit/Service/ObjectSource/DbalObjectSourceProviderTest.php`
  gains a cross-org resolution scenario; `SourceMapper` default filtering for
  ordinary callers is untouched and not weakened.
- **Deployment**: unblocks re-enabling `saasMode: true` on the shared dev
  instance without the `{"saasMode":false,"adminOverride":true}` workaround
  that has been in place since #2084/#2089.
