# Tasks

## 1. Schema

- [x] 1.1 Add the identity facet columns (`type`, `summary`, `oin`, `tooi`,
      `rsin`, `kvk`, `pki`, `image`) to `openregister_organisations`.
- [x] 1.2 Add the relationship facet columns (`registration_status`,
      `merged_into`, `merged_at`).
- [x] 1.3 Add the missing tenancy columns (`groups`, `storage_quota`,
      `bandwidth_quota`, `request_quota`) — the two silent defects.
- [x] 1.4 Add the `oin` and `merged_into` indexes.
- [x] 1.5 Keep every step additive and re-runnable (`hasColumn` / `hasIndex`).

## 2. Entity

- [x] 2.1 Declare the facet properties with their accessors.
- [x] 2.2 Key `_fieldTypes` by PROPERTY name so the declared casts execute.
- [x] 2.3 Add `isMerged()` and include the facets in `jsonSerialize()`.

## 3. Merge resolution

- [x] 3.1 Implement `OrganisationMapper::resolveMergeTarget()` — bounded,
      cycle-guarded, never failing open.
- [x] 3.2 Implement `findByUuidFollowingMerge()` as the read counterpart.
- [x] 3.3 Call the resolver from the live tenant-resolution path. Both entries
      into `fetchActiveOrganisationFromDatabase()` now walk it: the stored
      active UUID, and the oldest-membership auto-pick that runs when nothing
      is stored. The walk is guarded on `isMerged()`, so an unmerged row costs
      no query, and the survivor is written back to user config so the walk is
      a one-off per user per merge. Six regression tests in
      `tests/Unit/Service/ActiveOrganisationFollowsMergeTest.php`.

## 4. Tests

- [x] 4.1 Pin the field-type keys by property name.
- [x] 4.2 Prove `fromRow()` hydrates the datetime columns into `DateTime` —
      the assertion that raises a `TypeError` against the old registration.

## 5. Leaf-app consolidation

- [ ] 5.1 DECISION REQUIRED: the migration path for existing leaf-app
      organisation data. Adding the columns makes reuse possible; it does not
      move OpenCatalogi's publisher rows or Stackiq's vendor rows into them.
      The backfill must preserve the existing uuid/slug, and it needs a ruling
      on what happens when the same legal entity exists in BOTH leaf apps under
      different UUIDs. Until then the leaf apps keep their own records and OR's
      new columns stay empty.
- [ ] 5.2 Point the leaf apps at the OR organisation once 5.1 is decided.
