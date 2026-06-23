## 1. Request model + lifecycle + deadline (Piece 1)

- [ ] 1.1 Add `lib/Service/Gdpr/DataSubjectDeadline.php` — pure EU art-12 deadline helper: `computeDueAt(DateTimeInterface): DateTimeImmutable` (+1 month), `extend(DateTimeInterface $dueAt): DateTimeImmutable` (+2 months), `isOverdue(DateTimeInterface $deadline, DateTimeInterface $now): bool`, `daysRemaining(DateTimeInterface $deadline, DateTimeInterface $now): int`. No DI, no DB.
- [ ] 1.2 Add the `dataSubjectRequest` register/schema JSON (OR-shippable) with the GDPR rights taxonomy `type` enum, the status `enum`, and an `x-openregister-lifecycle` block (initial `received`; working `verifying`/`in-progress`; final `fulfilled`/`refused`/`closed`) plus `dueAt`/`extendedUntil` fields.
- [ ] 1.3 Unit tests for `DataSubjectDeadline` (dueAt = +1mo, extend = +2mo once, isOverdue both directions, daysRemaining).

## 2. Consumable service find/fulfil (Piece 2)

- [ ] 2.1 Add `lib/Service/Gdpr/DataSubjectRequestService.php` (DI-resolvable, RBAC + tenant scoped, NOT admin-gated). Reuse the `GdprEntity` index join (same SQL shape as `DsarService::matchEntities`, with LIKE-wildcard escaping) but honour `_rbac`/`_multitenancy` on object loads.
- [ ] 2.2 Implement `findSubjectData(string $subjectId, ?string $type, string $mode, bool $rbac, bool $multitenancy): array` — discover the subject's objects, RBAC/tenant-scoped, returning object + matched gdprEntities envelopes.
- [ ] 2.3 Implement `assembleAccessExport(string $subjectId, ?string $type): array` (art-15/20) — portable bundle of discovered objects + which PII attributes matched.
- [ ] 2.4 Implement `rectify(string $objectIdentifier, array $changes): ?array` (art-16) with DSAR processing-activity attribution.
- [ ] 2.5 Implement `erase(string $subjectId, ?string $type, string $mode, bool $dryRun): array` (art-17). Mode param: `pseudonymise` (field-level) | `whole-object` (soft-delete). Skip objects under `RetentionService::hasActiveLegalHold()` or immutable archival status; report them as `held`. Support dry-run.
- [ ] 2.6 Implement `setRestriction(string $objectIdentifier, bool, string $reason): ?array` (art-18) and `setObjection(string $objectIdentifier, bool, string $reason): ?array` (art-21) writing a generic restriction/objection marker on the object with DSAR attribution.
- [ ] 2.7 Add deadline pass-throughs (`computeDueAt`, `extend`, `isOverdue`) delegating to `DataSubjectDeadline`.
- [ ] 2.8 Add `lib/Controller/DataSubjectRequestController.php` + routes in `appinfo/routes.php` (`#[NoAdminRequired]`, RBAC-scoped via the service).

## 3. Tests

- [ ] 3.1 Unit test `DataSubjectRequestService`: `findSubjectData` returns a seeded subject's objects across 2 registers (RBAC-scoped); objects the caller can't read are excluded.
- [ ] 3.2 Unit test `erase` respects a legal-hold/retention flag (held object reported as `held`, not erased); both erase modes behave as specified; dry-run mutates nothing.
- [ ] 3.3 Unit test `assembleAccessExport` assembles the subject's data with matched PII attributes.
- [ ] 3.4 Unit test that fulfilment writes carry DSAR processing-activity attribution (audit recorded).

## 4. Validation + live-verify

- [ ] 4.1 Run the Unit suite; keep OR's suites green. `composer check:strict` on changed files; fix new findings + pre-existing in touched files.
- [ ] 4.2 LIVE-verify on :8080 (container PHP / OR API): seed a subject with objects in a scratch register; create a `dataSubjectRequest`; run `findSubjectData` + an access-export + a deadline computation; confirm a lifecycle transition + an audit row. Honest live vs unit.
- [ ] 4.3 `openspec validate gdpr-data-subject-rights --strict`; archive.

Acceptance criteria:
- The model + lifecycle + EU deadline are generic (no Dutch policy), additive, and ship without changing `DsarService`/`RetentionService`/`AvgRetentionService`.
- The consumable service is RBAC + tenant scoped (not admin-gated) and reuses `GdprEntity` index + `RetentionService` legal-hold guard + the immutable audit trail.
- `erase` mode is a parameter; legal-hold objects are reported `held`, never erased.

Quality:
- No new PHPCS/PHPMD/PHPStan/Psalm regressions; SPDX header + PHPDoc on every new file.
