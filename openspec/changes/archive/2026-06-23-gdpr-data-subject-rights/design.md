# Design — Generic GDPR data-subject-rights in OpenRegister

## Generic vs Dutch-locale split

The capability separates the **generic GDPR mechanic** (belongs in OR) from the
**Dutch-locale policy** (stays in the consuming app, e.g. pipelinq):

| Generic → OpenRegister | Dutch-locale → consuming app overlay |
| --- | --- |
| `dataSubjectRequest` model + status lifecycle | `kenmerk` AVG-YYYY-NNNN reference format |
| EU art-12 deadline (`dueAt` +1mo, single `extend()` +2mo, `isOverdue`) | BSN / BRP verification (`verzoekerBsn`, `verzoekerBsnGeverifieerd`) |
| rights taxonomy art-15/16/17/18/20/21 | AP (Autoriteit Persoonsgegevens) complaint URL, `weigering` denial (art-23) |
| `findSubjectData` cross-register discovery (RBAC/tenant-scoped) | FG / DPO naming + role model (`avg_dpo_group`) |
| `assembleAccessExport` portable bundle | 4-eyes citizen-correspondence drafts (Dutch wording) |
| `rectify` / `erase` (mode param) / `setRestriction` / `setObjection` | RvIG 5-year dossier retention; Procest DPIA linkage |
| immutable request audit (reuse `AuditTrailMapper` + `AuditHashService`) | secure one-time download token, PAdES-LTV signing |

## Reuse (don't duplicate)

- **`GdprEntity` PII index** (`openregister_entities` ⋈ `openregister_entity_relations`)
  — the same join `DsarService::matchEntities()` uses (with `escapeLikeParameter`
  for LIKE-wildcard safety). The new service reuses the join shape but loads
  objects **RBAC + tenant scoped** (`MagicMapper::find(id, _rbac:true, _multitenancy:true)`),
  whereas `DsarService` deliberately bypasses scoping (`_rbac:false`) behind an
  admin guard.
- **`RetentionService::hasActiveLegalHold()` + `validateNotImmutable()`** — the
  legal-hold / immutable archival guard that blocks erasure.
- **`ObjectEntity::setProcessingActivityId()`** — pins fulfilment writes to the
  configured DSAR processing activity (`DsarService::getDsarProcessingActivityUuid()`),
  so the existing `AuditTrailMapper::createAuditTrail()` hash-chained trail records
  them with the right attribution. No new audit code.
- **`x-openregister-lifecycle` + `TransitionEngine`** — already in
  `Schema::ANNOTATION_VOCABULARY`; the `dataSubjectRequest` status field uses it.
  No new annotation key needed.

## Consumable service contract (exact signatures)

```php
namespace OCA\OpenRegister\Service\Gdpr;

class DataSubjectRequestService
{
    public function findSubjectData(
        string $subjectId, ?string $type = null, string $mode = 'exact',
        bool $rbac = true, bool $multitenancy = true
    ): array;                                            // art-15/20 discovery

    public function assembleAccessExport(
        string $subjectId, ?string $type = null
    ): array;                                            // art-15/20 portable bundle

    public function rectify(string $objectIdentifier, array $changes): ?array;  // art-16

    public function erase(
        string $subjectId, ?string $type = null,
        string $eraseMode = 'pseudonymise',              // 'pseudonymise' | 'whole-object'
        bool $dryRun = false
    ): array;                                            // art-17, respects legal hold

    public function setRestriction(string $objectIdentifier, bool $restricted, string $reason): ?array; // art-18
    public function setObjection(string $objectIdentifier, bool $objected, string $reason): ?array;     // art-21

    // deadline pass-throughs (delegate to DataSubjectDeadline)
    public function computeDueAt(\DateTimeInterface $receivedAt): \DateTimeImmutable;
    public function extend(\DateTimeInterface $dueAt): \DateTimeImmutable;
    public function isOverdue(\DateTimeInterface $deadline, ?\DateTimeInterface $now = null): bool;
}
```

`erase()` returns `{ subject, type, eraseMode, dryRun, matchedCount, erased[],
held[], failed[], complete, failedCount }` — `held` is the new bucket for
legal-hold / immutable objects, distinct from `failed`.

## Pipelinq consumption guide

pipelinq's AVG surface thins to a Dutch-locale overlay:

- **Moves to OR / replaced by the OR service:** `DeadlineService` →
  `DataSubjectDeadline`; the request lifecycle + status graph →
  `dataSubjectRequest` schema; cross-register discovery in
  `EvidenceCollectionService::collectFromOpenRegister` → `findSubjectData`;
  export assembly → `assembleAccessExport` (pipelinq keeps its signing/token
  wrapper); `RetentionService` legal-hold semantics → OR's guard.
- **Stays as Dutch overlay:** `AvgNotificationService` (Dutch citizen drafts,
  AP reference), `DenialService` (`weigering`, mandatory AP URL),
  `AvgAccessService` (FG/DPO role model), `DpiaDetectionService`,
  BSN/BRP fields, 4-eyes, RvIG 5-year retention.

pipelinq's `avgVerzoek` becomes a Dutch-named projection over the generic
`dataSubjectRequest`: it adds `kenmerk`, `verzoekerBsn`, `verzoekerBsnGeverifieerd`,
`fgGeinformeerd`, `retentieTot`, `weigering`, and maps its `artikel` enum to the
generic `type`, while delegating deadline math, discovery, and fulfilment to the
OR capability.
