# content-versioning Specification

## Purpose
Implement draft/published content versioning with diff comparison and rollback capabilities for register objects. Users MUST be able to create named draft versions, collaborate on changes, compare versions with visual diffs, and promote drafts to the published (main) version. Only changed fields are stored as deltas to optimize storage.

**Source**: Gap identified in cross-platform analysis; four platforms implement content versioning.

## ADDED Requirements

### Requirement: Objects MUST support a draft/published lifecycle
Each object MUST have a published version (the current live data) and support one or more draft versions for work-in-progress changes.

#### Scenario: Create a draft version
- GIVEN a published object `melding-1` with title `Geluidsoverlast` and status `nieuw`
- WHEN the user creates a draft named `status-update`
- THEN a draft version MUST be created storing only the delta from the published version
- AND the published version MUST remain unchanged and accessible
- AND the draft MUST be accessible only by its creator and users with write permissions

#### Scenario: Edit a draft version
- GIVEN a draft `status-update` for `melding-1`
- WHEN the user changes the status to `in_behandeling` and adds a note
- THEN only the changed fields MUST be stored in the draft delta
- AND the published version MUST remain unchanged

#### Scenario: List drafts for an object
- GIVEN object `vergunning-1` has 2 drafts: `locatie-correctie` and `status-update`
- WHEN the user views the object's draft list
- THEN both drafts MUST be displayed with: name, creator, creation date, last modified date

### Requirement: Drafts MUST be promotable to published version
A draft version MUST be mergeable into the published version, replacing the current live data with the draft changes.

#### Scenario: Promote a draft to published
- GIVEN draft `status-update` for `melding-1` with status changed to `in_behandeling`
- WHEN the user promotes (publishes) the draft
- THEN the published version MUST be updated with the draft's changes
- AND the draft MUST be deleted after successful promotion
- AND an audit trail entry MUST record the promotion with the previous published state

#### Scenario: Promote draft with conflict
- GIVEN draft `status-update` was created when status was `nieuw`
- AND another user has since changed the published status to `in_behandeling`
- WHEN the draft creator tries to promote the draft
- THEN the system MUST detect the conflict on the `status` field
- AND display both values: draft value vs current published value
- AND the user MUST choose which value to keep before promoting

### Requirement: The system MUST support version comparison with visual diffs
Users MUST be able to compare any two versions (draft vs published, or two historical versions) with field-level diffs.

#### Scenario: Compare draft with published version
- GIVEN published `melding-1` has title `Overlast` and draft has title `Geluidsoverlast centrum`
- WHEN the user opens the diff view
- THEN each changed field MUST be displayed side-by-side:
  - Left (published): `Overlast`
  - Right (draft): `Geluidsoverlast centrum`
- AND unchanged fields MUST be displayed but visually de-emphasized
- AND added/removed text SHOULD be highlighted with color coding

#### Scenario: Compare two historical versions
- GIVEN an object with 5 historical versions (from audit trail)
- WHEN the user selects version 2 and version 4 for comparison
- THEN the diff view MUST show all fields that changed between those two versions

### Requirement: The system MUST support version rollback
Users MUST be able to revert an object to any previous version from its history.

#### Scenario: Rollback to previous version
- GIVEN object `melding-1` is at version 5 (status: `afgehandeld`)
- AND version 3 had status `in_behandeling`
- WHEN the user rolls back to version 3
- THEN the object MUST be updated to match version 3's data
- AND this MUST create a new version 6 (not delete versions 4-5)
- AND the audit trail MUST record: `Rolled back to version 3`

#### Scenario: Rollback with referential integrity check
- GIVEN rolling back would set a reference field to an object that no longer exists
- WHEN the rollback is attempted
- THEN the system MUST warn about the broken reference
- AND the user MUST confirm before proceeding

### Requirement: Version history MUST be retained
All published versions MUST be retained in the audit trail for compliance and traceability.

#### Scenario: View version history
- GIVEN object `vergunning-1` has been modified 8 times
- WHEN the user opens the version history
- THEN all 8 versions MUST be listed with: version number, date, user, summary of changes
- AND each version MUST be viewable (read-only snapshot)
- AND any version MUST be selectable for diff comparison

### Current Implementation Status
- **Partial:**
  - `AuditTrailMapper` (`lib/Db/AuditTrailMapper.php`) stores full snapshots and changed fields for every object mutation, providing version history
  - `RevertHandler` (`lib/Service/Object/RevertHandler.php`) implements object reversion to a previous state using audit trail data, with `revert(objectEntity, until, overwriteVersion)` method
  - `AuditTrailMapper::revertObject()` reconstructs objects from audit trail entries
  - Audit trail entries include: action, changed fields (old/new values), user, timestamp — enabling diff comparison
  - Version history is viewable through the audit trail API/controller
- **NOT implemented:**
  - Named draft versions — no concept of "draft" vs "published" state on objects
  - Draft creation, editing, and listing separate from the main object
  - Delta-only storage for drafts (current audit trail stores full snapshots + changes)
  - Draft promotion with conflict detection (concurrent edit merging)
  - Visual diff comparison UI (side-by-side field comparison with color coding)
  - Rollback to a specific version (the RevertHandler exists but rolls back to a point in time, not a specific version number)
  - Referential integrity checks during rollback
  - Draft access control (draft visible only to creator + write-permission users)
- **Partial:**
  - The audit trail effectively provides version history (each entry is a version), but there is no explicit version numbering
  - RevertHandler provides rollback but to a DateTime, not a named version

### Standards & References
- **Git-style versioning** — Conceptual model for draft/publish workflow
- **JSON Patch (RFC 6902)** — Standard for describing changes between JSON documents (applicable to delta storage)
- **JSON Merge Patch (RFC 7396)** — Simpler alternative for field-level diffs
- **Nextcloud Files versioning** — Reference implementation for version management in Nextcloud
- **CMIS (Content Management Interoperability Services)** — Standard for content versioning in document management systems

### Specificity Assessment
- The spec is thorough with well-defined scenarios covering the full lifecycle (create draft, edit, promote, conflict, rollback).
- The existing audit trail and RevertHandler provide a solid foundation to build upon.
- Missing: database schema for draft storage (separate table? draft flag on ObjectEntity?); API endpoints for draft CRUD and promotion; how drafts are stored in MagicMapper mode vs. normal mode.
- Ambiguous: whether "delta-only storage" means JSON Patch format or a simpler changed-fields approach; how conflict detection works when multiple fields change.
- Open questions:
  - Should multiple drafts per object be allowed, or only one active draft at a time?
  - How do drafts interact with webhooks and events — should draft creation/promotion trigger events?
  - Should drafts be searchable or excluded from search results?
  - What happens to drafts when the published version is updated by another user?
