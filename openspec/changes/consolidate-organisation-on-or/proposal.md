# consolidate-organisation-on-or

## Why

OpenRegister already owns a first-class tenant `Organisation` — ADR-002 makes
the organisation UUID the ONLY tenant key. What it did not own was the rest of
what an organisation *is*, so every leaf app grew its own copy:

- **OpenCatalogi** keeps an `organization` publisher record carrying the
  statutory identifiers (`oin`, `tooi`, `rsin`, `kvk`, `pki`) plus `summary`
  and `image`.
- **Stackiq** keeps a vendor/provider record carrying `registrationStatus` and
  the merge relationship between two vendors that turned out to be one party.

ADR-022 §3 is explicit: *"If OR already defines a `contact` or `case` or
`organisation` model, an app using those concepts MUST reuse the OR schema."*
Those per-app copies are therefore the defect, not the design. They also make
the same legal entity resolvable under two different keys, which is the exact
shape ADR-002 exists to prevent.

Two live, silent defects in the existing table block that reuse and are
repaired here:

1. **`groups` has no column, on any instance, ever.**
   `Version1Date20250102000000` adds it, guarded by
   `hasTable('openregister_organisations') === true` — but that class sorts
   FIVE MONTHS BEFORE `Version1Date20250622212509`, which CREATES the table.
   Nextcloud runs migrations in class-name order, so the guard is false, the
   step succeeds, and the column is never added. Verified against a live
   database: `UPDATE oc_openregister_organisations SET groups='[]'` →
   `ERROR: column "groups" ... does not exist`.

2. **`storage_quota` / `bandwidth_quota` / `request_quota` have no column
   anywhere.** The entity declares all three and `__construct()` calls
   `addType()` on them, but the only migration creating those columns
   (`Version1Date20251101120000`) targets `openregister_applications`. They
   were copy-pasted from `Db/Application`.

Both are reachable, not theoretical. `QBMapper::update()` builds its SET list
from `getUpdatedFields()`, so a marked field with no column is an SQL error at
write time, not a no-op:

- `TenantLifecycleService::provision()` calls `setGroups([...])`, so
  provisioning a tenant could never have succeeded.
- `PUT /api/organisations/{uuid}` whitelists `storageQuota`, `bandwidthQuota`,
  `requestQuota` and `groups` in `OrganisationController` — each a guaranteed
  500.

A third defect surfaced while writing the entity: `_fieldTypes` was registered
under COLUMN names (`storage_quota`, `provisioned_at`) for part of the entity.
`Entity::__call()` resolves a setter to `lcfirst(substr($method, 3))` and
`Entity::fromRow()` maps a column to a property before looking the type up, so
a snake_case registration matches nothing and the cast never runs. For the
`?DateTime` properties that is not cosmetic: `fromRow()` assigned the raw
string onto a typed property and threw
`TypeError: Cannot assign string to property $provisionedAt of type ?DateTime`
— every read of an organisation row carrying a lifecycle timestamp was a 500.

## What Changes

- **Identity facet.** `openregister_organisations` gains `type`, `summary`,
  `oin`, `tooi`, `rsin`, `kvk`, `pki`, `image` — the columns OpenCatalogi's
  publisher record carried. These answer the same question `uuid` answers
  ("which legal entity is this"), so they belong on the organisation row.
- **Relationship facet.** The table gains `registration_status`, `merged_into`
  and `merged_at` — what Stackiq's vendor record carried. `merged_into` is core
  rather than app-specific because a merge changes WHICH UUID IS AUTHORITATIVE.
- **Tenancy repairs.** The table gains `groups` and the three quota columns
  described above.
- **Merge resolution.** `OrganisationMapper::resolveMergeTarget()` walks a
  merge chain to the surviving organisation, bounded and cycle-guarded;
  `findByUuidFollowingMerge()` is its read counterpart.
- **Field-type keys corrected** to property names so the declared casts
  actually execute.
- Two indexes: `oin` (how a publication resolves its publisher) and
  `merged_into` (walked on tenant resolution).

**BREAKING:** none. Every column is nullable or defaulted and no existing row
is rewritten.

**NOT IN THIS CHANGE — see Impact.** The leaf-app backfill and the wiring of
merge resolution into the live tenant-resolution path are deliberately
separate; both need a product decision that this change does not make.

## Capabilities

### Added Capabilities

- `consolidated-organisation`: the organisation row carries the identity and
  relationship facets the leaf apps kept privately, and a merged-away
  organisation resolves to its survivor before its UUID is used as a scope.

## Impact

**Affected code:** `lib/Db/Organisation.php` (facet properties, field-type
keys, `isMerged()`, `jsonSerialize()`), `lib/Db/OrganisationMapper.php`
(`resolveMergeTarget()`, `findByUuidFollowingMerge()`),
`lib/Migration/Version1Date20260828100000.php` (additive schema).

**Tests:** `tests/Unit/Db/OrganisationTest.php` pins the field-type keys by
PROPERTY name and proves `fromRow()` hydrates the datetime columns into
`DateTime` objects — the assertion that fails with a `TypeError` against the
pre-change registration.

**Dependencies:** no new packages.

**Open, and intentionally not decided here:**

1. **The leaf apps' migration path for existing organisation data.** Adding the
   columns makes reuse possible; it does not move OpenCatalogi's publisher rows
   or Stackiq's vendor rows into them. That backfill must preserve the existing
   uuid/slug rather than minting new ones (identifiers already written into
   stored data are frozen), and it needs a decision on what happens when the
   same legal entity exists in BOTH leaf apps under different UUIDs. Until that
   is decided the leaf apps keep their own records and OR's new columns stay
   empty — which is additive and safe, but is not yet the consolidation.
2. **Wiring merge resolution into tenant resolution.** `resolveMergeTarget()`
   is implemented and tested but has no caller. Calling it from the live
   tenant-resolution path changes which rows every scoped query returns for a
   merged organisation, so it is held for its own change with its own
   regression suite.
