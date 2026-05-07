---
status: implemented
retrofit_extensions:
  - REQ-017
---
# Content Versioning


# Content Versioning
## Purpose
Content versioning provides a complete lifecycle for register objects, enabling users to track every change as a numbered version, create named draft versions for work-in-progress edits, compare any two versions with field-level diffs, and roll back to any previous state. This capability is essential for government compliance (WOO, Archiefwet), editorial workflows where changes require review before publication, and multi-user collaboration where concurrent edits must be managed safely.

## Requirements

### Requirement: Every save operation MUST produce a new version
Each create or update operation on an object MUST increment the object's semantic version number and record the full change set in the audit trail. The version number MUST follow semantic versioning (MAJOR.MINOR.PATCH) where PATCH increments on every save, MINOR increments on draft promotion, and MAJOR increments on schema-breaking changes or explicit user action.

#### Scenario: Version increment on first creation
- **GIVEN** a user creates a new object in schema `meldingen` with title `Geluidsoverlast`
- **WHEN** the object is saved via `SaveObject`
- **THEN** the object MUST be assigned version `1.0.0`
- **AND** `AuditTrailMapper.createAuditTrail()` MUST record the creation with action `create`
- **AND** the audit trail entry MUST store the full object snapshot in the `changed` field

#### Scenario: Version increment on update
- **GIVEN** object `melding-1` is at version `1.0.3`
- **WHEN** the user updates the status from `nieuw` to `in_behandeling`
- **THEN** the version MUST increment to `1.0.4`
- **AND** the audit trail entry MUST record both old and new values: `{"status": {"old": "nieuw", "new": "in_behandeling"}}`

#### Scenario: Version increment on bulk update
- **GIVEN** 50 objects in schema `meldingen` are updated in a single bulk operation
- **WHEN** the bulk update completes
- **THEN** each object MUST have its version incremented independently
- **AND** each object MUST have its own audit trail entry (silent mode MUST NOT suppress version tracking on the parent object)

#### Scenario: Version number persists across API responses
- **GIVEN** object `melding-1` is at version `1.0.4`
- **WHEN** any user retrieves the object via `GET /index.php/apps/openregister/api/objects/{register}/{schema}/{id}`
- **THEN** the response MUST include `"version": "1.0.4"` in the JSON body

### Requirement: Objects MUST support a draft/published lifecycle

> **Status: deferred** — No DraftService or draft version entity found in codebase as of 2026-04-30 coverage scan. Track separately before implementing.

Each object MUST have a published version (the current live data) and support one or more named draft versions for work-in-progress changes. Drafts MUST store only the delta (changed fields) relative to the published version to optimize storage. The published version MUST remain accessible and unmodified while drafts exist.

#### Scenario: Create a draft version
- **GIVEN** a published object `melding-1` with title `Geluidsoverlast` and status `nieuw` at version `1.0.3`
- **WHEN** the user creates a draft named `status-update`
- **THEN** a draft version MUST be created storing only the delta from the published version
- **AND** the published version MUST remain unchanged and accessible at version `1.0.3`
- **AND** the draft MUST be accessible only by its creator and users with write permissions on the object's register/schema

#### Scenario: Edit a draft version
- **GIVEN** a draft `status-update` for `melding-1`
- **WHEN** the user changes the status to `in_behandeling` and adds a note field
- **THEN** only the changed fields (`status`, `note`) MUST be stored in the draft delta
- **AND** the published version MUST remain unchanged
- **AND** retrieving the draft MUST return the published version merged with the delta

#### Scenario: List drafts for an object
- **GIVEN** object `vergunning-1` has 2 drafts: `locatie-correctie` and `status-update`
- **WHEN** the user requests `GET /index.php/apps/openregister/api/objects/{register}/{schema}/{id}/versions?status=draft`
- **THEN** both drafts MUST be returned with: key, name, creator, creation date, last modified date, and a summary of changed fields

#### Scenario: Read object with draft applied
- **GIVEN** published object `melding-1` has title `Geluidsoverlast` and draft `update-1` changes title to `Geluidsoverlast centrum`
- **WHEN** the user requests `GET /index.php/apps/openregister/api/objects/{register}/{schema}/{id}?version=update-1`
- **THEN** the response MUST return the published object with the draft delta merged on top
- **AND** the response MUST include a `_version` metadata field indicating this is a draft view

#### Scenario: Draft with nested relations
- **GIVEN** published object `zaak-1` has a relation to `contact-1`
- **WHEN** a draft changes the relation to `contact-2`
- **THEN** the draft delta MUST store only the changed relation reference, not the full related object
- **AND** rendering the draft MUST resolve the relation to `contact-2`

### Requirement: Drafts MUST be promotable to published version

> **Status: deferred** — Depends on draft/published lifecycle (see above). Not implemented as of 2026-04-30.

A draft version MUST be mergeable into the published version, replacing the current live data with the draft changes. Promotion MUST create a new version entry in the audit trail and MUST increment the MINOR version number.

#### Scenario: Promote a draft to published
- **GIVEN** draft `status-update` for `melding-1` (published at `1.0.3`) with status changed to `in_behandeling`
- **WHEN** the user promotes the draft via `POST /index.php/apps/openregister/api/objects/{register}/{schema}/{id}/versions/{key}/promote`
- **THEN** the published version MUST be updated to `1.1.0` with the draft's changes applied
- **AND** the draft MUST be deleted after successful promotion
- **AND** an audit trail entry MUST be created with action `version.promote` recording the previous published state

#### Scenario: Promote draft with conflict detection
- **GIVEN** draft `status-update` was created when the published status was `nieuw`
- **AND** another user has since changed the published status to `in_behandeling` (now at version `1.0.4`)
- **WHEN** the draft creator tries to promote the draft
- **THEN** the system MUST detect the conflict on the `status` field (draft base was `1.0.3` but published is now `1.0.4`)
- **AND** the API MUST return HTTP 409 Conflict with a body listing conflicting fields, their draft values, and their current published values
- **AND** the user MUST resolve conflicts before the promotion can proceed

#### Scenario: Promote draft with no conflicts
- **GIVEN** draft `locatie-update` changes only the `locatie` field
- **AND** the published version has been updated since draft creation but only the `status` field changed
- **WHEN** the user promotes the draft
- **THEN** the promotion MUST succeed without conflict because the changed fields do not overlap

#### Scenario: Force-promote draft ignoring conflicts
- **GIVEN** a draft has conflicts with the published version
- **WHEN** an administrator promotes the draft with `?force=true`
- **THEN** the draft values MUST overwrite the conflicting published values
- **AND** the audit trail MUST record that the promotion was forced with details of overwritten fields

### Requirement: The system MUST support version comparison with visual diffs

> **Status: deferred** — No diffing service found in codebase as of 2026-04-30 coverage scan. Track separately before implementing.

Users MUST be able to compare any two versions (draft vs published, any two historical versions) with field-level diffs. The diff MUST identify added, removed, and modified fields with their old and new values.

#### Scenario: Compare draft with published version
- **GIVEN** published `melding-1` has title `Overlast` and status `nieuw`
- **AND** draft `update-1` has title `Geluidsoverlast centrum` and status `in_behandeling`
- **WHEN** the user requests `GET /index.php/apps/openregister/api/objects/{register}/{schema}/{id}/versions/diff?from=main&to=update-1`
- **THEN** the response MUST include a field-level diff:
  - `{"title": {"old": "Overlast", "new": "Geluidsoverlast centrum"}, "status": {"old": "nieuw", "new": "in_behandeling"}}`
- **AND** unchanged fields MUST NOT appear in the diff response (but MAY be included with a `changed: false` marker if `?includeUnchanged=true` is passed)

#### Scenario: Compare two historical versions by version number
- **GIVEN** an object with versions `1.0.0` through `1.0.5` recorded in the audit trail
- **WHEN** the user requests a diff between version `1.0.1` and version `1.0.4`
- **THEN** the diff MUST show the cumulative changes between those two versions across all fields
- **AND** for each changed field, the response MUST show the value at `1.0.1` and the value at `1.0.4`

#### Scenario: Compare two historical versions by audit trail ID
- **GIVEN** an object with audit trail entries ID 42 and ID 87
- **WHEN** the user requests a diff between audit trail entry 42 and 87
- **THEN** the system MUST reconstruct the object state at each audit trail entry using `AuditTrailMapper.revertObject()`
- **AND** the diff MUST show field-level differences between those two reconstructed states

#### Scenario: Diff for relation changes
- **GIVEN** version `1.0.2` has relation `assignee` pointing to `contact-1` (name: `Jan de Vries`)
- **AND** version `1.0.5` has relation `assignee` pointing to `contact-2` (name: `Piet Jansen`)
- **WHEN** the user requests a diff between `1.0.2` and `1.0.5`
- **THEN** the diff MUST show the relation change with both the reference IDs and a human-readable summary: `{"assignee": {"old": {"id": "contact-1", "display": "Jan de Vries"}, "new": {"id": "contact-2", "display": "Piet Jansen"}}}`

### Requirement: The system MUST support version rollback
Users MUST be able to revert an object to any previous version from its history. Rollback MUST create a new version (not delete intermediate versions) to preserve the complete audit trail. The existing `RevertHandler` and `AuditTrailMapper.revertObject()` MUST be extended to support rollback by version number in addition to the existing DateTime and audit trail ID modes.

#### Scenario: Rollback to a specific version number
- **GIVEN** object `melding-1` is at version `1.0.5` (status: `afgehandeld`)
- **AND** version `1.0.2` had status `in_behandeling`
- **WHEN** the user sends `POST /index.php/apps/openregister/api/revert/{register}/{schema}/{id}` with body `{"version": "1.0.2"}`
- **THEN** the `RevertHandler.revert()` MUST reconstruct the object state at version `1.0.2`
- **AND** the object MUST be saved as a new version `1.0.6` with the reconstructed data
- **AND** the audit trail MUST record action `revert` with metadata `{"revertedToVersion": "1.0.2"}`
- **AND** `ObjectRevertedEvent` MUST be dispatched via `IEventDispatcher`

#### Scenario: Rollback to a point in time
- **GIVEN** object `melding-1` has been modified 8 times over the past week
- **WHEN** the user reverts to a DateTime `2026-03-15T14:00:00Z`
- **THEN** the `AuditTrailMapper.findByObjectUntil()` MUST find all audit entries after that timestamp
- **AND** `AuditTrailMapper.revertChanges()` MUST apply reversions in reverse chronological order
- **AND** the result MUST be saved as a new version

#### Scenario: Rollback preserves intermediate history
- **GIVEN** object `melding-1` has versions `1.0.0` through `1.0.5`
- **WHEN** the user rolls back to version `1.0.2`
- **THEN** versions `1.0.3`, `1.0.4`, and `1.0.5` MUST remain in the audit trail
- **AND** the new version `1.0.6` MUST be added (rollback never deletes history)

#### Scenario: Rollback with referential integrity check
- **GIVEN** rolling back to version `1.0.2` would set a relation field to object `contact-99` which has since been deleted
- **WHEN** the rollback is attempted
- **THEN** the system MUST return HTTP 409 Conflict with a warning about the broken reference
- **AND** the response MUST include the specific fields with broken references and the missing object identifiers
- **AND** the user MUST confirm with `?force=true` before proceeding, or the rollback MUST be rejected

#### Scenario: Rollback of a locked object
- **GIVEN** object `melding-1` is locked by user `behandelaar-2` via `LockHandler`
- **WHEN** user `behandelaar-1` attempts a rollback
- **THEN** the `RevertHandler` MUST throw a `LockedException` with the locking user's identity
- **AND** the rollback MUST NOT proceed

### Requirement: Version history MUST be queryable via API
The system MUST expose a version history API that lists all versions of an object with metadata. The API MUST support pagination, filtering by date range and action type, and sorting. This builds on the existing `AuditTrailController` and `AuditHandler.getLogs()`.

#### Scenario: List version history with pagination
- **GIVEN** object `vergunning-1` has been modified 150 times
- **WHEN** the user requests `GET /index.php/apps/openregister/api/objects/{register}/{schema}/{id}/audit-trail?_page=1&_limit=30`
- **THEN** the response MUST return the 30 most recent versions with: version number, action, user, userName, timestamp, summary of changed fields
- **AND** the response MUST include pagination metadata: `total: 150`, `page: 1`, `pages: 5`

#### Scenario: Filter version history by action type
- **GIVEN** object `melding-1` has audit entries for `create`, `update`, `revert`, `lock`, `unlock`, and `version.promote` actions
- **WHEN** the user requests `?action=update,revert`
- **THEN** only entries with action `update` or `revert` MUST be returned

#### Scenario: Filter version history by date range
- **GIVEN** object `melding-1` has entries spanning from 2025-01-01 to 2026-03-19
- **WHEN** the user requests `?date_from=2026-01-01&date_to=2026-03-01`
- **THEN** only entries within that date range MUST be returned

#### Scenario: View a specific historical version as read-only snapshot
- **GIVEN** object `vergunning-1` has version `1.0.4` in its audit trail
- **WHEN** the user requests `GET /index.php/apps/openregister/api/objects/{register}/{schema}/{id}?version=1.0.4`
- **THEN** the system MUST reconstruct the object at version `1.0.4` by replaying audit trail entries
- **AND** the response MUST include the full object state at that version with a `_readOnly: true` metadata flag

#### Scenario: Version history includes revert metadata
- **GIVEN** version `1.0.6` was created by reverting to version `1.0.2`
- **WHEN** the user views the version history
- **THEN** version `1.0.6` MUST display action `revert` with metadata `{"revertedToVersion": "1.0.2"}`

### Requirement: Version metadata MUST capture comprehensive context
Every version (audit trail entry) MUST record who made the change, when, from which session and IP address, and optionally why. This metadata MUST be sufficient for compliance auditing under WOO, Archiefwet, and GDPR Article 30.

#### Scenario: Metadata fields on every audit trail entry
- **GIVEN** user `behandelaar-1` (display name `Jan de Vries`) updates an object
- **THEN** the audit trail entry MUST include:
  - `user`: `behandelaar-1`
  - `userName`: `Jan de Vries`
  - `session`: the PHP session ID
  - `request`: the Nextcloud request ID
  - `ipAddress`: the client IP address
  - `created`: server-side UTC timestamp
  - `version`: the resulting object version number
  - `register`: the register ID
  - `schema`: the schema ID

#### Scenario: Optional change reason
- **GIVEN** the user provides a `_reason` field in the update request body
- **WHEN** the object is saved
- **THEN** the audit trail entry's `changed` field MUST include a `_reason` key with the provided text
- **AND** the reason MUST be displayed in the version history UI

#### Scenario: System-initiated changes record system context
- **GIVEN** a referential integrity CASCADE operation updates object `order-1` because `person-1` was deleted
- **WHEN** the audit trail entry is created
- **THEN** the `user` MUST be `System`
- **AND** the `changed` field MUST include the trigger context as documented in the deletion-audit-trail spec: `{"triggerObject": "person-1", "triggerSchema": "person"}`

### Requirement: Version storage MUST use a delta strategy for drafts and full snapshots for published versions

> **Status: deferred** — Draft delta storage not implemented as of 2026-04-30. Audit trail stores full diffs (implemented); draft-specific delta storage is not.

Published version history MUST store the full changed-field diff (old and new values) in the audit trail as currently implemented by `AuditTrailMapper.createAuditTrail()`. Draft versions MUST store only the delta (changed fields with new values only) relative to the current published version to minimize storage overhead.

#### Scenario: Audit trail stores full diff for published versions
- **GIVEN** object `melding-1` at version `1.0.3` has title `Overlast` and status `nieuw`
- **WHEN** the title is changed to `Geluidsoverlast` and saved as version `1.0.4`
- **THEN** the audit trail entry MUST store: `{"title": {"old": "Overlast", "new": "Geluidsoverlast"}}`
- **AND** unchanged fields MUST NOT appear in the `changed` field

#### Scenario: Draft stores delta only
- **GIVEN** published object `melding-1` has 25 fields
- **WHEN** a draft changes only 2 fields (title and status)
- **THEN** the draft MUST store only: `{"title": "Geluidsoverlast centrum", "status": "in_behandeling"}`
- **AND** the storage size MUST be proportional to the number of changed fields, not the total object size

#### Scenario: Reconstruct full object from draft delta
- **GIVEN** the draft delta is `{"title": "Geluidsoverlast centrum"}` and the published object has 25 fields
- **WHEN** the draft is rendered
- **THEN** the system MUST merge the published object with the draft delta
- **AND** the result MUST contain all 25 fields with the title replaced by the draft value

### Requirement: Version retention MUST be configurable per register
Administrators MUST be able to configure how long version history (audit trail entries) is retained per register. The retention policy MUST comply with Archiefwet requirements (minimum 10 years for government records) and MUST support the existing `expires` field and `ObjectRetentionHandler` mechanisms.

#### Scenario: Configure retention period per register
- **GIVEN** register `archief` requires 20-year audit retention for WOO compliance
- **WHEN** the admin sets the retention period to 20 years via register settings
- **THEN** `AuditTrailMapper.setExpiryDate()` MUST set the `expires` field to `created + 20 years` for all audit entries in that register
- **AND** the `LogCleanUpTask` cron job MUST NOT delete entries before their `expires` date

#### Scenario: Default retention period
- **GIVEN** a register has no custom retention period configured
- **WHEN** audit trail entries are created
- **THEN** the `expires` field MUST default to `created + 30 days` (as currently implemented in `AuditTrailMapper.createAuditTrail()`)

#### Scenario: Retention period change applies to existing entries
- **GIVEN** register `zaken` has 1000 audit entries with `expires` set to 30 days
- **WHEN** the admin increases retention to 5 years
- **THEN** `AuditTrailMapper.setExpiryDate()` MUST update the `expires` field for all existing entries without an expiry date
- **AND** entries that already have an expiry date SHOULD be recalculated if the new period is longer

#### Scenario: WOO-exempt registers allow shorter retention
- **GIVEN** register `temp-imports` is marked as not subject to WOO/Archiefwet
- **WHEN** the admin sets retention to 7 days
- **THEN** the system MUST allow the shorter retention period without warning

### Requirement: Version operations MUST respect RBAC permissions
Creating, viewing, promoting, and rolling back versions MUST be governed by the existing OpenRegister permission model. The `PermissionHandler` and `SecurityService` MUST enforce access control on all version operations.

#### Scenario: Read permission required for version history
- **GIVEN** user `medewerker-1` has read permission on schema `meldingen` in register `gemeente`
- **WHEN** the user requests the version history of object `melding-1`
- **THEN** the audit trail entries MUST be returned

#### Scenario: No read permission blocks version history
- **GIVEN** user `burger-1` has no read permission on schema `intern-meldingen`
- **WHEN** the user requests the version history of an object in that schema
- **THEN** the system MUST return HTTP 403 Forbidden

#### Scenario: Write permission required for draft creation
- **GIVEN** user `medewerker-1` has read-only permission on schema `vergunningen`
- **WHEN** the user attempts to create a draft version
- **THEN** the system MUST return HTTP 403 Forbidden

#### Scenario: Admin-only rollback in restricted registers
- **GIVEN** register `archief` is configured to restrict rollback to administrators only
- **WHEN** a regular user with write permission attempts a rollback
- **THEN** the system MUST return HTTP 403 Forbidden with message indicating rollback requires admin rights

#### Scenario: Draft visibility restricted to creator and write-permission users
- **GIVEN** user `medewerker-1` creates a draft for object `melding-1`
- **AND** user `medewerker-2` has read-only permission on the schema
- **WHEN** `medewerker-2` lists versions for `melding-1`
- **THEN** the draft created by `medewerker-1` MUST NOT be visible to `medewerker-2`
- **AND** the published version history MUST still be visible

### Requirement: Search MUST be configurable to include or exclude draft versions
By default, search queries MUST return only published versions of objects. Users MUST be able to opt in to searching across draft content with an explicit query parameter.

#### Scenario: Default search excludes drafts
- **GIVEN** object `melding-1` has a published title `Overlast` and a draft with title `Geluidsoverlast centrum`
- **WHEN** a user searches for `Geluidsoverlast` without any version parameter
- **THEN** the search MUST NOT return `melding-1` (the published title does not match)

#### Scenario: Search with draft inclusion
- **GIVEN** the same scenario as above
- **WHEN** a user searches for `Geluidsoverlast` with parameter `?_includeDrafts=true`
- **THEN** the search MUST return `melding-1` with an indication that it matched on a draft version

#### Scenario: Search across historical versions
- **GIVEN** object `melding-1` previously had title `Klacht geluid` at version `1.0.1` but now has title `Overlast`
- **WHEN** a user searches for `Klacht` with parameter `?_searchHistory=true`
- **THEN** the search SHOULD return `melding-1` with an indication that it matched on a historical version

### Requirement: Bulk version operations MUST be supported
The system MUST support bulk rollback and bulk draft promotion for multiple objects in a single request. Bulk operations MUST be atomic (all-or-nothing) or report partial success with details of which objects succeeded and which failed.

#### Scenario: Bulk rollback to a point in time
- **GIVEN** 20 objects in schema `meldingen` were erroneously updated by an import at `2026-03-19T10:00:00Z`
- **WHEN** the admin sends a bulk rollback request for all objects in schema `meldingen` with `until: "2026-03-19T09:59:59Z"`
- **THEN** each object MUST be reverted to its state before the erroneous update
- **AND** each object MUST receive a new version number
- **AND** the response MUST report how many objects were successfully reverted and list any failures

#### Scenario: Bulk draft promotion
- **GIVEN** 5 objects have drafts named `release-v2` ready for publication
- **WHEN** the admin promotes all `release-v2` drafts in a single request
- **THEN** each object's draft MUST be promoted to published
- **AND** if any promotion fails (e.g., conflict), the response MUST indicate which objects failed and why
- **AND** successfully promoted objects MUST NOT be rolled back due to other objects' failures (partial success is acceptable)

#### Scenario: Bulk operation respects per-object locking
- **GIVEN** 10 objects are selected for bulk rollback
- **AND** 2 of those objects are locked by another user
- **WHEN** the bulk rollback is executed
- **THEN** the 8 unlocked objects MUST be reverted successfully
- **AND** the 2 locked objects MUST be reported as failed with `LockedException` details

### Requirement: Version operations MUST perform efficiently at scale
The system MUST handle objects with hundreds of versions without degrading API response times. Version history queries MUST use indexed database columns and pagination. Full object reconstruction from audit trail MUST use an efficient reverse-application strategy.

#### Scenario: Version history query performance
- **GIVEN** object `vergunning-1` has 500 audit trail entries
- **WHEN** the user requests page 1 of the version history with limit 30
- **THEN** the query MUST use the index on `(object, created)` columns in the `openregister_audit_trails` table
- **AND** the response time MUST be under 200ms

#### Scenario: Object reconstruction performance
- **GIVEN** object `vergunning-1` has 500 versions and the user requests to view version `1.0.10`
- **WHEN** the system reconstructs the object at version `1.0.10`
- **THEN** the `AuditTrailMapper.revertObject()` MUST apply only the minimal set of changes needed (versions `1.0.11` through current in reverse)
- **AND** the reconstruction MUST complete in under 500ms for objects with up to 1000 versions

#### Scenario: Draft storage does not bloat the main object table
- **GIVEN** 100 objects each have 3 active drafts
- **WHEN** the system queries for published objects
- **THEN** draft data MUST be stored in a separate mechanism (version/draft table or audit trail) and MUST NOT increase the row count or query complexity of the main object table

#### Scenario: Audit trail statistics remain accurate
- **GIVEN** 10,000 audit trail entries exist for a register
- **WHEN** `AuditTrailMapper.getStatistics()` is called
- **THEN** the count and size statistics MUST be accurate and return in under 100ms using the existing `COUNT(id)` and `SUM(size)` aggregate queries

### Requirement: Version events MUST be dispatched for integration
All version lifecycle operations MUST fire Nextcloud events via `IEventDispatcher` to allow other apps and n8n workflows to react. This extends the existing `ObjectRevertedEvent` pattern to cover all version operations.

#### Scenario: Revert fires ObjectRevertedEvent
- **GIVEN** a user reverts object `melding-1` to version `1.0.2`
- **WHEN** the revert completes successfully
- **THEN** `ObjectRevertedEvent` MUST be dispatched with the reverted object and the `until` parameter
- **AND** registered listeners (including n8n webhook triggers) MUST receive the event

#### Scenario: Draft promotion fires event
- **GIVEN** a user promotes draft `status-update` for object `melding-1`
- **WHEN** the promotion completes
- **THEN** a `VersionPromotedEvent` MUST be dispatched with the object, the draft key, and the new version number

#### Scenario: Draft creation fires event
- **GIVEN** a user creates a draft for object `melding-1`
- **WHEN** the draft is saved
- **THEN** a `DraftCreatedEvent` MUST be dispatched with the object UUID, draft key, and creator

#### Scenario: Webhooks triggered by version events
- **GIVEN** a webhook is configured for schema `meldingen` listening on `version.promote` events
- **WHEN** a draft is promoted
- **THEN** the `WebhookService` MUST fire the webhook with a CloudEvent payload including the version metadata

### Requirement: Versions MUST support WOO and archiving compliance
For objects subject to WOO (Wet open overheid) and Archiefwet, the complete version history MUST be exportable as part of an archive package. Version metadata MUST include the organisation identifier, processing activity, and confidentiality level as recorded in the `AuditTrail` entity.

#### Scenario: Export version history for a WOO request
- **GIVEN** a WOO request covers all versions of object `besluit-1` from 2025
- **WHEN** the archivist exports the version history with `?date_from=2025-01-01&date_to=2025-12-31&format=json`
- **THEN** the export MUST include all audit trail entries for that period
- **AND** each entry MUST include: version, action, changed fields, user, timestamp, organisationId, confidentiality, retentionPeriod

#### Scenario: Version history includes organisation context
- **GIVEN** an audit trail entry was created within organisation context `OIN:00000001234567890000`
- **WHEN** the version history is exported
- **THEN** each entry MUST include the `organisationId`, `organisationIdType`, and `processingActivityId` fields from the `AuditTrail` entity

#### Scenario: Confidentiality-restricted version access
- **GIVEN** object `intern-besluit-1` has `confidentiality: "confidential"` on its audit trail entries
- **WHEN** a user without the appropriate clearance requests the version history
- **THEN** the system MUST filter or redact entries based on the confidentiality level

### Requirement: The version key "main" MUST be reserved for the published version
The key `main` MUST always refer to the current published version of an object. Users MUST NOT be able to create a draft with the key `main`. This follows the Directus convention for clear semantic distinction between published and draft content.

#### Scenario: Reject draft creation with reserved key
- **GIVEN** a user attempts to create a draft with key `main`
- **WHEN** the request is processed
- **THEN** the system MUST return HTTP 422 Unprocessable Entity with message `The key "main" is reserved for the published version`

#### Scenario: Access published version via main key
- **GIVEN** object `melding-1` has a published version and 2 drafts
- **WHEN** the user requests `GET /index.php/apps/openregister/api/objects/{register}/{schema}/{id}?version=main`
- **THEN** the response MUST return the current published version (equivalent to requesting without a version parameter)

#### Scenario: Draft keys must be URL-friendly
- **GIVEN** a user creates a draft with key `Status Update v2!`
- **WHEN** the request is processed
- **THEN** the system MUST reject the key and return HTTP 422 with a message requiring lowercase alphanumeric characters and hyphens only

## Current Implementation Status
- **Implemented:**
  - `ObjectEntity` has a `version` field (string, semantic versioning format `MAJOR.MINOR.PATCH`)
  - `AuditTrailMapper.createAuditTrail()` records every create/update/delete with full changed-field diffs (old and new values), user context, session, IP address, and timestamp
  - `AuditHandler.getLogs()` retrieves audit trail entries for an object with filtering by action, user, and date range
  - `RevertHandler.revert()` reverts an object to a previous state using audit trail data, dispatches `ObjectRevertedEvent`
  - `AuditTrailMapper.revertObject()` reconstructs object state by applying audit trail changes in reverse
  - `AuditTrailMapper.findByObjectUntil()` supports three revert modes: DateTime, audit trail ID, and semantic version string
  - `RevertController` exposes the revert API at `POST /api/revert/{register}/{schema}/{id}` accepting `datetime`, `auditTrailId`, or `version` parameters
  - `LockHandler` prevents rollback of locked objects (integrated in `RevertHandler`)
  - `AuditTrail` entity includes comprehensive metadata: uuid, action, changed, user, userName, session, request, ipAddress, version, created, organisationId, organisationIdType, processingActivityId, confidentiality, retentionPeriod, expires, size
  - `AuditTrailMapper.clearLogs()` respects the `expires` field for retention-based cleanup
  - `AuditTrailMapper.setExpiryDate()` sets expiry dates based on configurable retention period
  - Version number increment on revert (PATCH increment in `AuditTrailMapper.revertObject()`)
  - `AuditTrailMapper.getStatistics()` and `getDetailedStatistics()` for version/audit analytics
- **NOT implemented:**
  - Named draft versions with delta-only storage (no draft/published lifecycle on objects)
  - Draft creation, editing, listing, and rendering APIs
  - Draft promotion with conflict detection
  - Visual diff comparison API endpoint (the data exists in audit trail `changed` field but no dedicated diff endpoint)
  - Bulk version operations (bulk rollback, bulk draft promotion)
  - Version-specific events beyond `ObjectRevertedEvent` (no `VersionPromotedEvent`, `DraftCreatedEvent`)
  - Search integration for draft content or historical version content
  - WOO/archiving export of version history
  - Configurable per-register retention (retention is global, not per-register)
  - RBAC for version-specific operations (rollback uses object-level permissions, no register-level rollback restriction)
  - Confidentiality-based version access filtering
  - Reserved `main` key convention for published version

## Standards & References
- **JSON Patch (RFC 6902)** -- Standard for describing changes between JSON documents, applicable to delta storage format
- **JSON Merge Patch (RFC 7396)** -- Simpler alternative for field-level diffs used in draft delta storage
- **Semantic Versioning 2.0.0 (semver.org)** -- Version numbering scheme for objects (MAJOR.MINOR.PATCH)
- **Nextcloud Files versioning** -- Reference implementation for version management within the Nextcloud ecosystem
- **CMIS (Content Management Interoperability Services)** -- Standard for content versioning in document management systems
- **Archiefwet 1995** -- Dutch archival law requiring long-term retention of government records including version history
- **WOO (Wet open overheid)** -- Dutch open government act requiring public access to government information, necessitating complete version trails
- **GDPR Article 30** -- Processing records requirement, relevant to version metadata (who, when, why)
- **BIO (Baseline Informatiebeveiliging Overheid)** -- Government information security baseline, logging and audit requirements
- **NEN 2082** -- Records management standard, audit trail requirements
- **Directus Content Versioning** -- Competitor reference: named versions with delta storage and promote workflow
- **Strapi Draft/Publish + History** -- Competitor reference: separate database rows for draft/published, full snapshot history

## Cross-Referenced Specs
- **audit-trail-immutable** -- Defines the underlying audit trail infrastructure (hash chaining, immutability, retention) that version history builds upon
- **deletion-audit-trail** -- Defines how referential integrity cascade operations are logged, relevant to rollback with broken references
- **referential-integrity** -- Defines CASCADE, SET_NULL, SET_DEFAULT, RESTRICT behaviors that interact with version rollback

## Nextcloud Integration Analysis

- **Status**: Partially implemented in OpenRegister
- **Existing Implementation**: `ObjectEntity.version` field stores semantic version strings. `AuditTrailMapper` provides the complete audit infrastructure (create, query, revert, statistics, retention). `RevertHandler` orchestrates rollback with lock checking and event dispatch. `RevertController` exposes the revert API. `AuditHandler` provides filtered log retrieval. The `AuditTrail` entity captures comprehensive metadata including GDPR/WOO-relevant fields (organisationId, processingActivityId, confidentiality, retentionPeriod).
- **Nextcloud Core Integration**: Uses NC's `Entity`/`QBMapper` patterns for all database entities. Fires events via `IEventDispatcher` (currently `ObjectRevertedEvent`). Integrates with NC's session and request infrastructure for audit metadata. Could implement NC's `IProvider` for the Activity app to surface version changes in the NC activity stream. Draft storage should use NC's file versioning patterns conceptually but store structured data in the database.
- **Recommendation**: The version history and rollback foundation is solid and production-ready. The primary gaps are: (1) named draft versions with delta storage and promotion workflow, (2) a dedicated diff comparison API endpoint, (3) per-register retention configuration, and (4) version-specific events beyond revert. These enhancements would bring OpenRegister to feature parity with Directus and Strapi's versioning capabilities while adding government-compliance features (WOO export, confidentiality filtering) that neither competitor offers.

### REQ-017: The system MUST support listing and restoring file versions via Nextcloud files_versions

When Nextcloud's `files_versions` app is enabled, the system MUST expose version history and restore operations for files attached to register objects. The `FileVersioningHandler` MUST wrap `IVersionManager` to list all historical snapshots with metadata (versionId, timestamp, size, author, isCurrent flag) and MUST support restoring a specific version by its timestamp-based identifier. When `files_versions` is disabled, listing MUST degrade gracefully by returning an empty version array with a warning, while restoring MUST throw an Exception.

#### Scenario: List versions when files_versions is enabled
- **GIVEN** the `files_versions` Nextcloud app is enabled
- **WHEN** `FileVersioningHandler::listVersions($file)` is called
- **THEN** the response MUST include a `versions` array where the first entry represents the current file version (`isCurrent: true`) with fields `versionId`, `timestamp`, `size`, `author`, `authorDisplayName`, `label`, and `isCurrent`
- **AND** each historical version MUST have `versionId` in the format `v-{unix_timestamp}` and `isCurrent: false`

#### Scenario: Graceful degradation when files_versions is disabled
- **GIVEN** the `files_versions` Nextcloud app is NOT enabled
- **WHEN** `FileVersioningHandler::listVersions($file)` is called
- **THEN** the response MUST return `{versions: [], warning: "File versioning is not enabled on this instance"}`
- **AND** `FileVersioningHandler::restoreVersion($file, $versionId)` MUST throw an Exception with the same message

#### Scenario: Restore a specific file version
- **GIVEN** the `files_versions` app is enabled and file `/user/files/report.pdf` has a historical version with `versionId: "v-1710892800"`
- **WHEN** `FileVersioningHandler::restoreVersion($file, "v-1710892800")` is called
- **THEN** the system MUST locate the version whose timestamp matches `1710892800`
- **AND** call `IVersionManager::rollback($version)` to restore the file content
- **AND** return `true` on success
- **AND** throw an Exception with message `"Version not found"` if no matching timestamp exists

#### Notes
- The `IVersionManager` is resolved via `\OCP\Server::get()` with a class_exists guard since `OCA\Files_Versions\Versions\IVersionManager` is not always available (depends on enabled apps). This is an observed runtime resolution pattern, not dependency injection.
- The `getCurrentUserId()` private method falls back to the literal string `'system'` when no authenticated session exists (background job context).
