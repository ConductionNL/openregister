# Design: archival-for-apps

## Destruction list creation

- `RetentionService::destructionRefusal(ObjectEntity, string $today, array $excludeUuids): ?string` returns the first rule that refuses an object, or null. The private `isEligibleForDestruction()` becomes `destructionRefusal(...) === null`, so the sweep and the new entry cannot drift apart.
- Reasons: `not_found`, `not_nominated_for_destruction`, `record_not_live`, `action_date_not_reached`, `record_frozen`, `legal_hold`, `already_on_a_list`. A uuid named twice is judged and listed once.
- `DestructionListCreator::createFor(array $uuids)` loads each object (no RBAC, as the sweep does), judges it, builds the list with `RetentionService::createDestructionList()` and saves it in the configured destruction-list register and schema with the sweep's own save call. It throws `InvalidArgumentException` when no register and schema are configured. `createdBy` is the signed-in user, or `system`.
- Route: archivist or admin, like every other destruction-list route. 201 with `{uuid, status, entryCount, refused}`; 422 with `refused` when none is eligible; 400 for a body without a list of uuids or over 1000 uuids; 409 when not configured.
- Not in scope: the review notification the sweep sends to the archivist group. A list created by an app shows in `GET /api/archival/destruction-lists` like any other.

## Certificates

- `DestructionListRepository::findCertificates(?string $listUuid)` reads the executed lists and loads each list's `certificateUuid`. Returns `{results, missing}`.
- The route answers `{results, total, missing, configured}`; `configured: false` when no destruction-list register is set, as `listDestructionLists` does.

## Selection lists in an app's import

- `SelectionListSeeder::seed(array $entries, ?string $appId)` validates each entry (category a non-empty string; action one of `Appraisal::ALL_ALIASES`; `retentionYears` a non-negative integer, required unless the action keeps permanently), reads every stored row once, and matches on `categorie` + `organisatie`. A matching row with the same values is `unchanged`, a different one is updated in place, no match is created. Returns `{created, updated, unchanged, failed: [{category, reason}]}`.
- `bron` is the entry's `source`, else `app:<appId>`. `selectielijstVersie` is set only when the entry names a `version`; an instance that pins a version applies only rows of that version (`SelectielijstResolver::pickApplicableRow`).
- `ImportHandler::importFromJson()` calls it after mappings when `components.selectionLists` is present and reports the counts under `selectionLists`. No selectielijst register configured: every entry fails with that reason, the rest of the import goes on.
