## 1. Declarative merge config

- [x] 1.1 Add `x-openregister-merge` to `Schema::ANNOTATION_VOCABULARY` in `lib/Db/Schema.php`.
- [x] 1.2 Add `lib/Service/Merge/MergeAnnotationValidator.php` — shape-validate the block (object; `reversalWindowDays` positive int if present; string fields), returning errors, never throwing.
- [x] 1.3 Wire a non-fatal `validateMergeAnnotation()` hook in `lib/Db/SchemaMapper.php` alongside `validateSurvivorshipAnnotation` — malformed → logged warning, import still succeeds.

## 2. mergeOperation register

- [x] 2.1 Add `lib/Settings/merge_operation_register.json` (shape of `trust_configuration_register.json`) declaring the `mergeOperation` schema — `mergedIntoUuid`, `mergedFromUuids[]`, `reason`, `preMergeSnapshot`, `reversible`, `mergedAt`, `reversedAt`, `reversedBy` — with NO `x-openregister-seed` objects; placeholder nil UUIDs where ids are needed.

## 3. Event

- [x] 3.1 Add `lib/Event/ObjectsMergedEvent.php` extending `OCP\EventDispatcher\Event`, carrying survivor uuid, mergedFrom uuids, mergeOperation id, and an `isReversal` flag with getters (SPDX + `@spec`).

## 4. Merge service

- [x] 4.1 Add `lib/Service/Merge/MergeService.php` with `previewMerge` / `executeMerge` / `reverseMerge` / `isReversible` / `reversalDeadline` / `buildSnapshot`, entity-type-agnostic, reading `x-openregister-merge` config (default `reversalWindowDays=30`).
- [x] 4.2 Implement `previewMerge` side-effect-free: recompute survivor via `SurvivorshipResolver` over the union of both objects' linked source records; return projected golden record + provenance + reversal deadline; reject self-merge / unreadable.
- [x] 4.3 Implement `executeMerge` as one unit: `buildSnapshot` → relink loser's source records via `sourceLinkField` → recompute survivor (`SurvivorshipResolver`) → flip statuses → persist a `mergeOperation` (`reversible: true`) via `ObjectService` → dispatch `ObjectsMergedEvent`; reject self-merge / already-merged / non-active survivor.
- [x] 4.4 Implement `reverseMerge`/`isReversible`/`reversalDeadline`: restore both objects + source links from `preMergeSnapshot` inside the window, set `reversedAt`/`reversedBy`/`reversible: false`, dispatch `ObjectsMergedEvent(isReversal: true)`; reject outside-window with no mutation.

## 5. REST surface

- [x] 5.1 Add `lib/Controller/MergeController.php` (`#[NoAdminRequired]`, RBAC via `ObjectService`, auth style of `DuplicateController`) delegating preview / execute / reverse to `MergeService`.
- [x] 5.2 Register the three merge routes in `appinfo/routes.php` (ADR-016); confirm every route target method exists and every Response-returning method is routed (ADR-029 reachability).

## 6. Quality & tests

- [x] 6.1 Add PHPUnit tests (the CI way — `php:8.3-cli` + OCP stubs, no live NC/OR): `MergeService` execute/reverse/preview/isReversible/reversalDeadline and `MergeAnnotationValidator` shape cases.
- [x] 6.2 Add SPDX + `@license`/`@copyright` docblock + `@spec openspec/changes/mdm-merge-engine/...` tags on every new PHP file; run `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) clean.
- [x] 6.3 Run `openspec validate mdm-merge-engine --strict` and the Hydra mechanical gates (spdx, route-auth, route-reachability, no-admin-idor) clean.

## Acceptance criteria

- `x-openregister-merge` is retained on schema config and shape-validated non-fatally at import.
- The `mergeOperation` register imports with the schema and zero seed rows.
- `previewMerge` writes nothing and dispatches no event; `executeMerge` relinks source records, recomputes the survivor via `SurvivorshipResolver`, writes exactly one `mergeOperation` row, and dispatches `ObjectsMergedEvent`.
- `reverseMerge` restores the snapshot inside the window and flips `reversible=false`; outside the window it is rejected with no mutation.
- Every read/write goes through `ObjectService`; controller methods are `#[NoAdminRequired]` and RBAC-scoped; a caller without access gets forbidden/not-found, not a merge.
- No app-specific sync queue is introduced; propagation is the OR event only.

## Quality checklist

- SPDX + `@license`/`@copyright` + `@spec` on every new PHP file.
- Every route target method exists on `MergeController`; every Response-returning method is routed (ADR-029).
- `#[NoAdminRequired]` + RBAC guard present on every controller method (no IDOR).
- PHPUnit verified the CI way (`php:8.3-cli` + OCP stubs, no live container).
- `composer check:strict` and `openspec validate mdm-merge-engine --strict` pass.
