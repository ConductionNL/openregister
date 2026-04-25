---
status: proposed
---

# Integration: Contacts

## Purpose

Surface NC Contacts linked to OR objects through the registry with first-class role support. The `single-entity` widget surface is the canonical person chip across all Conduction apps.

**Standards**: RFC 6350 (vCard), CardDAV, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md), [nextcloud-entity-relations](../../../../specs/nextcloud-entity-relations/spec.md)

---

## Requirements

### Requirement: Contacts Provider Registration

`ContactsProvider` registered with id='contacts', group='core', requiredApp='contacts', storage='link-table'.

#### Scenario: Present when Contacts installed

- **GIVEN** Contacts app installed
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the provider MUST be included

---

### Requirement: Role-Grouped Tab Display

The tab SHALL group linked contacts by role with section headers.

#### Scenario: Tab groups by role

- **GIVEN** an object with 3 contacts: Jan (role=applicant), Piet (role=handler), Klaas (no role)
- **WHEN** `CnContactsTab` renders
- **THEN** three sections MUST appear: "Applicants" (Jan), "Handlers" (Piet), "Other" (Klaas)

#### Scenario: Link-with-role workflow

- **WHEN** the user links an existing contact and selects role "advisor"
- **THEN** the link record MUST store `role='advisor'`
- **AND** the contact MUST appear under the "Advisors" section

---

### Requirement: Reverse Lookup

Clicking a contact SHALL open a flyout listing all OR objects (across all schemas the user can read) that have this contact linked.

#### Scenario: Reverse lookup returns cross-schema results

- **GIVEN** Jan is linked to object `case-1` (schema `case`) as applicant AND object `permit-7` (schema `permit`) as holder
- **WHEN** the user clicks Jan in the tab and opens reverse lookup
- **THEN** both objects MUST be listed with their schema + role annotations

---

### Requirement: Canonical Person Chip on Single-Entity Surface

`CnContactCard` at `surface='single-entity'` SHALL be the preferred rendering for schema properties of type person reference across all Conduction apps.

#### Scenario: Single-entity chip shows avatar + name + hover details

- **GIVEN** `entityId` pointing to a vCard
- **WHEN** `CnContactCard` renders with `surface='single-entity'`
- **THEN** the chip MUST include avatar (or initials fallback), display name, and hover-expanded details (email, phone)

---

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'contacts'` SHALL render the canonical person chip.

#### Scenario: Person-reference property renders via widget

- **GIVEN** schema `{assignedHandler: { type: 'string', referenceType: 'contacts' }}` and object `{assignedHandler: 'vcard-uuid-jan'}`
- **WHEN** `CnDetailGrid` renders the object
- **THEN** the row MUST contain `CnContactCard` with `entityId='vcard-uuid-jan'` and `surface='single-entity'`

---

### Requirement: Permission Inheritance

`ContactsProvider::requiresPermission()` SHALL return `null`. Access inherits from object RBAC + Contacts address book permissions.
