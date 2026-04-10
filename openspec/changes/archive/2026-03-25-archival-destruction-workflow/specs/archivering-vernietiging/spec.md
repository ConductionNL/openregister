---
status: draft
capability: archivering-vernietiging
---

# Archival & Destruction Workflow - Delta Spec

## ADDED Requirements

### Requirement: Archival metadata on objects via retention field
Objects MUST store archival metadata in the existing `retention` JSON field with MDTO-conformant keys.

#### Scenario: Set archival metadata
- GIVEN an object in register `zaakregister`
- WHEN archival metadata is set via `PUT /api/archival/objects/{id}/retention`
- THEN the `retention` field MUST contain:
  - `archiefnominatie`: one of `vernietigen`, `bewaren`, `nog_niet_bepaald`
  - `archiefactiedatum`: ISO 8601 date for the archival action
  - `archiefstatus`: one of `nog_te_archiveren`, `gearchiveerd`, `vernietigd`, `overgebracht`
  - `classificatie`: selection list category code
- AND `archiefnominatie` defaults to `nog_niet_bepaald` if not set

#### Scenario: Calculate archiefactiedatum from selection list
- GIVEN a selection list entry with category `B1`, bewaartermijn 5 years, action `vernietigen`
- AND an object with classificatie `B1` and a close date of 2026-03-01
- WHEN the system calculates archival dates
- THEN `archiefactiedatum` MUST be 2031-03-01
- AND `archiefnominatie` MUST be `vernietigen`

### Requirement: Selection list (selectielijst) CRUD
Administrators MUST be able to manage selection list entries that map categories to retention rules.

#### Scenario: CRUD selection list entries
- GIVEN an admin user
- WHEN they POST to `/api/archival/selection-lists` with `{ "category": "B1", "retentionYears": 5, "action": "vernietigen", "description": "Korte bewaartermijn" }`
- THEN a selection list entry is created
- AND it is retrievable via GET, updatable via PUT, deletable via DELETE

#### Scenario: Schema-level override
- GIVEN a default retention of 10 years for category A1
- AND a schema override setting 20 years for schema `vertrouwelijk-dossier`
- WHEN retention is calculated for objects in that schema
- THEN 20 years MUST be used instead of 10

### Requirement: Destruction list generation and approval
Objects past their archiefactiedatum with archiefnominatie `vernietigen` MUST be processable through a destruction workflow.

#### Scenario: Generate destruction list
- GIVEN 15 objects with archiefactiedatum before today and archiefnominatie `vernietigen`
- WHEN `POST /api/archival/destruction-lists/generate` is called
- THEN a destruction list MUST be created containing all 15 object references
- AND the list status is `pending_review`

#### Scenario: Approve destruction list
- GIVEN a destruction list with status `pending_review`
- WHEN an archivist calls `POST /api/archival/destruction-lists/{id}/approve`
- THEN all objects in the list MUST be permanently deleted
- AND audit trail entries with action `archival.destroyed` MUST be created
- AND the destruction list status changes to `completed`
- AND the destruction list itself is retained as an archival record

#### Scenario: Reject items from destruction list
- GIVEN a destruction list with 15 objects
- WHEN the archivist calls `POST /api/archival/destruction-lists/{id}/reject` with 3 object IDs
- THEN those 3 objects are removed from the list
- AND their archiefactiedatum is extended by the original retention period

### Requirement: Background destruction check job
A TimedJob MUST run daily to identify objects due for destruction and generate destruction lists.

#### Scenario: Scheduled destruction check
- GIVEN objects with archiefactiedatum <= today and archiefnominatie `vernietigen` and archiefstatus `nog_te_archiveren`
- WHEN the DestructionCheckJob runs
- THEN a destruction list is generated for review
- AND a notification is sent to users with archival management permissions

### Requirement: Audit trail for archival actions
All archival actions MUST be logged in the audit trail.

#### Scenario: Destruction audit trail
- GIVEN an approved destruction list
- WHEN objects are destroyed
- THEN each deletion creates an audit trail entry with:
  - action: `archival.destroyed`
  - metadata: destruction list ID, approving user, timestamp
