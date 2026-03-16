# row-field-level-security Specification

## Purpose
Implement dynamic per-record access rules based on field values and per-field visibility rules based on user roles. Beyond schema-level RBAC, the system MUST support row-level security (RLS) where access to individual objects depends on the object's own properties (e.g., department, classification level), and field-level security (FLS) where different users see different fields of the same object.

**Source**: Gap identified in cross-platform analysis; two platforms implement row/field-level security.

## ADDED Requirements

### Requirement: Schemas MUST support row-level security rules
Schema definitions MUST accept row-level security rules that filter objects based on the current user's context and the object's field values.

#### Scenario: Restrict access by department field
- GIVEN schema `meldingen` with RLS rule: `user.group CONTAINS object.afdeling`
- AND melding `melding-1` has `afdeling: "sociale-zaken"`
- AND user `jan` is in group `sociale-zaken`
- AND user `pieter` is in group `ruimtelijke-ordening`
- WHEN both users list meldingen
- THEN `jan` MUST see `melding-1`
- AND `pieter` MUST NOT see `melding-1`

#### Scenario: Restrict access by classification level
- GIVEN schema `documenten` with RLS rule: `user.clearanceLevel >= object.vertrouwelijkheid`
- AND document `doc-1` has `vertrouwelijkheid: 3`
- AND user `behandelaar` has `clearanceLevel: 2`
- AND user `manager` has `clearanceLevel: 4`
- WHEN both users query the document
- THEN `behandelaar` MUST receive HTTP 403 for `doc-1`
- AND `manager` MUST be able to access `doc-1`

#### Scenario: Owner-based access
- GIVEN schema `aanvragen` with RLS rule: `user.id == object.eigenaar OR user.group == "admin"`
- AND aanvraag `aanvraag-1` has `eigenaar: "jan"`
- WHEN user `jan` accesses `aanvraag-1`, access MUST be granted
- AND when user `pieter` (non-admin) accesses `aanvraag-1`, access MUST be denied
- AND when user `admin-1` (in admin group) accesses `aanvraag-1`, access MUST be granted

### Requirement: RLS rules MUST apply to all access methods
Row-level security MUST be enforced on REST API, GraphQL, search results, exports, and the UI.

#### Scenario: RLS in search results
- GIVEN RLS restricts meldingen by department
- WHEN user `jan` (sociale-zaken) searches for meldingen
- THEN only meldingen where `afdeling: "sociale-zaken"` MUST appear in results
- AND facet counts MUST reflect only the accessible objects

#### Scenario: RLS in data export
- GIVEN user `jan` exports meldingen to CSV
- THEN the export MUST only contain objects passing the RLS rules
- AND the export MUST NOT include objects from other departments

### Requirement: Schemas MUST support field-level security
Individual properties MUST be configurable with visibility rules based on user roles.

#### Scenario: Hide sensitive field from basic users
- GIVEN schema `inwoners` with property `bsn` configured with FLS: visible only to group `bsn-geautoriseerd`
- AND user `medewerker-1` is NOT in `bsn-geautoriseerd`
- WHEN `medewerker-1` reads an inwoner object
- THEN the `bsn` field MUST be omitted from the response
- AND all other fields MUST be returned normally

#### Scenario: Show sensitive field to authorized users
- GIVEN the same configuration
- AND user `specialist` IS in `bsn-geautoriseerd`
- WHEN `specialist` reads the same inwoner object
- THEN the `bsn` field MUST be included in the response

#### Scenario: Field-level security in list views
- GIVEN FLS hides `bsn` from `medewerker-1`
- WHEN `medewerker-1` views the inwoners list
- THEN the `bsn` column MUST NOT appear in the table
- AND the column MUST appear for authorized users

### Requirement: RLS rules MUST support the $CURRENT_USER context variable
Rules MUST be able to reference the current user's properties (ID, groups, custom attributes).

#### Scenario: Use $CURRENT_USER in rule
- GIVEN an RLS rule: `object.assignedTo == $CURRENT_USER.id`
- WHEN user `jan` (ID: `jan`) queries objects
- THEN only objects where `assignedTo` equals `jan` MUST be returned

### Requirement: RLS and FLS MUST be combinable with schema-level RBAC
Row and field-level security MUST be additive to (not replacing) schema-level RBAC.

#### Scenario: Combined RBAC + RLS
- GIVEN schema `meldingen` with RBAC allowing group `behandelaars` to read
- AND RLS rule: `object.afdeling IN user.groups`
- WHEN user `jan` (in `behandelaars` and `sociale-zaken`) queries
- THEN RBAC check MUST pass (jan is in behandelaars)
- AND RLS MUST further filter to only sociale-zaken meldingen

### Requirement: Security rules MUST be auditable
All access decisions (grant/deny) based on RLS/FLS MUST be loggable for compliance.

#### Scenario: Log RLS denial
- GIVEN RLS denies user `pieter` access to `melding-1`
- WHEN logging is enabled for access decisions
- THEN a log entry MUST record: user, object, rule that denied access, timestamp
