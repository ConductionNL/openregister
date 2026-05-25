---
retrofit: true
---

# Object Lifecycle

## Purpose

Two object-management behaviors implemented in the `Service\Object` handler layer have no owning requirement. Object **locking** is referenced by other capabilities (content-versioning rollback refuses to revert a locked object) but the lock-state contract itself — how lock presence is determined, how expiry is honored, and what lock metadata is exposed — is unspecified; the write side (`LockHandler::lockObject` / `unlock`) carries only a change-tasks annotation. Object **merge / deduplication** (`MergeHandler::mergeObjects`) — folding a duplicate source object into a target, transferring its files, relations, and inbound references, then soft-deleting the source — is entirely unspecified. This change retroactively documents both observed behaviors so the handler contracts are anchored.

**Source**: Reverse-spec retrofit of shipped code — `LockHandler` and `MergeHandler`. Behavior is documented as observed, not changed.

## ADDED Requirements

### Requirement: Objects MUST expose an expiry-aware lock-state contract
MUST report whether an object is currently locked, treating an expired lock as unlocked, and MUST expose the lock's metadata (who locked it, when, optional process identifier, and expiry) when a live lock is present.

`LockHandler::isLocked()` MUST resolve the object by id or UUID, read its `locked` metadata, and return `false` when no lock metadata is present. When lock metadata carries an `expiresAt` timestamp that is in the past, `isLocked()` MUST treat the lock as expired and return `false`. `getLockInfo()` MUST return `null` when the object is not locked and otherwise MUST return a normalized array exposing `locked_at`, `locked_by`, `process`, and `expires_at`. Both reads MUST be defensive: a lookup failure MUST be logged and degrade to "not locked" (`false` / `null`) rather than propagating an exception, so a read-side lock probe never breaks the calling flow.

#### Scenario: Active lock is reported as locked
- **GIVEN** an object whose `locked` metadata has no `expiresAt` or an `expiresAt` in the future
- **WHEN** `isLocked()` is called with its identifier
- **THEN** it MUST return `true`
- **AND** `getLockInfo()` MUST return an array with `locked_by`, `locked_at`, `process`, and `expires_at` keys sourced from the metadata

#### Scenario: Expired lock is reported as unlocked
- **GIVEN** an object whose `locked.expiresAt` is in the past
- **WHEN** `isLocked()` is called
- **THEN** it MUST return `false`

#### Scenario: Lookup failure degrades to not-locked
- **GIVEN** the object cannot be resolved (lookup throws)
- **WHEN** `isLocked()` or `getLockInfo()` is called
- **THEN** the failure MUST be logged
- **AND** `isLocked()` MUST return `false` and `getLockInfo()` MUST return `null` rather than throwing

### Requirement: The system MUST support merging a duplicate object into a target within the same register and schema
MUST merge a source object into a target object — applying property overrides, transferring or deleting the source's files, transferring or dropping its relations, updating inbound references from other objects, and soft-deleting the source — while rejecting merges across mismatched register or schema, and MUST return a structured report of the actions taken.

`MergeHandler::mergeObjects()` MUST require a non-empty target identifier and MUST throw an `InvalidArgumentException` when it is missing. It MUST resolve both source and target across all magic-table sources, throwing a not-found exception when either cannot be located. It MUST reject the merge with an `InvalidArgumentException` when the source and target belong to a different register or a different schema. Merge behavior MUST be configurable per call: `fileAction` of `transfer` (default) or `delete`, `relationAction` of `transfer` (default) or `drop`, and a reference action governing how inbound references to the source are rewritten to the target. After applying property overrides and the configured file/relation/reference handling, the source object MUST be soft-deleted. The method MUST return a merge report containing the original source and target, the merged result, the per-category actions taken, aggregate statistics (properties changed, files transferred/deleted, relations transferred/dropped, references updated), and any warnings or errors — rather than throwing on partial, recoverable problems.

#### Scenario: Merge requires a target
- **GIVEN** a merge request with no `target`
- **WHEN** `mergeObjects()` is called
- **THEN** it MUST throw an `InvalidArgumentException` before resolving any object

#### Scenario: Cross-register or cross-schema merge is rejected
- **GIVEN** a source and target object that belong to different registers (or different schemas)
- **WHEN** `mergeObjects()` is called
- **THEN** it MUST throw an `InvalidArgumentException` and MUST NOT soft-delete the source

#### Scenario: Successful merge transfers and soft-deletes the source
- **GIVEN** a source and target in the same register and schema, with `fileAction: transfer` and `relationAction: transfer`
- **WHEN** `mergeObjects()` is called with property overrides
- **THEN** the target MUST receive the property overrides, the source's files and relations MUST be transferred, inbound references MUST be rewritten to the target, and the source MUST be soft-deleted
- **AND** the returned report MUST include the merged object and statistics for the actions taken

## Non-Functional

- **i18n (ADR-007)**: No user-facing strings (ADR-007 n/a) — both behaviors are backend handler contracts; lock metadata keys and merge-report keys are machine-facing, and any operator-facing surfacing is owned by the controller/UI specs.
- **Backward compatibility**: Reverse-spec of already-shipped `LockHandler`/`MergeHandler` code; no production behavior change.

## Acceptance Criteria

- `LockHandler::isLocked()` / `getLockInfo()` carry `@spec` annotations to the locking requirement, and `MergeHandler::mergeObjects()` to the merge requirement.
- The scenarios above hold for the shipped implementation (expiry honored, lookup failure degrades to not-locked, cross-register/schema merge rejected before soft-delete).
