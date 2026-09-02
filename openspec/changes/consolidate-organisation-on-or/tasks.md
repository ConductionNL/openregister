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
- [ ] 3.3 Call the resolver from the live tenant-resolution path. HELD: this
      changes which rows every scoped query returns for a merged organisation,
      so it needs its own change and its own regression suite.

## 4. Tests

- [x] 4.1 Pin the field-type keys by property name.
- [x] 4.2 Prove `fromRow()` hydrates the datetime columns into `DateTime` —
      the assertion that raises a `TypeError` against the old registration.

## 5. Leaf-app consolidation

- [x] 5.1 DECIDED and built: `openregister:organisations:adopt`. The uuid is
      the idempotency key and is preserved, following the rule dossiq's
      `migrate-partners` arrived at — a leaf row may carry no slug, and two
      rows sharing a name are routine, so a name-derived key would skip the
      second as "already migrated" and silently merge two legal entities.
      Where the same entity already exists under a different uuid the rows are
      NOT collapsed: the adopted row is created and pointed at the existing one
      through `mergedInto`, so both uuids keep resolving. Matching is on OIN,
      then RSIN, then KVK, normalised for punctuation, and never on a name.
      Lowest id is canonical; a merged-away candidate loses to a live one.
      Properties Organisation has no column for are NAMED before the write,
      because OpenRegister discards an undeclared property and answers 200.
      Dry-run by default. Proven live on the dev instance including the
      negative control (no shared identifier, no merge reported).
- [ ] 5.2 Point the leaf apps at the OR organisation. opencatalogi maps
      9-for-9 onto Organisation apart from `tooiIdentifier`. Stackiq's 21
      properties leave 8 with no column (`xml`, `contactsUid`,
      `contactpersonen`, `deelnames`, `participants`, `samenwerkingtype`,
      `registeredBy`, `publicationDate`/`depublicationDate`), so it needs a
      field ruling before its schema can be retired.
