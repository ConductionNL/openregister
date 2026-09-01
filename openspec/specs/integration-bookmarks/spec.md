---
status: done
---

# integration-bookmarks Specification

## Purpose
Links Nextcloud Bookmarks to OpenRegister objects, delegating URL title/favicon scraping to the NC Bookmarks create endpoint instead of re-implementing it. Displays linked bookmarks with their Bookmarks-side tags as filter chips, renders the standard four widget surfaces, and shows a favicon chip for `referenceType: 'bookmarks'` properties. Access control defers to Bookmarks' own ACLs.
## Requirements
### Requirement: Bookmarks Provider Registration

The system SHALL register `BookmarksProvider` with id='bookmarks', group='docs', requiredApp='bookmarks', storage='link-table'.

#### Scenario: Bookmarks provider is registered

- **WHEN** the integration registry is enumerated
- **THEN** the system MUST include a provider with id='bookmarks', group='docs', requiredApp='bookmarks', and storage='link-table'

### Requirement: Add URL Flow Delegates Scraping

"Add URL" MUST call the NC Bookmarks create endpoint to extract title/favicon; OR MUST NOT re-implement scraping.

#### Scenario: Add URL delegates to NC Bookmarks

- **WHEN** a user adds a URL via the "Add URL" flow
- **THEN** the system MUST call the NC Bookmarks create endpoint to extract title/favicon
- **AND** the system MUST NOT re-implement scraping

### Requirement: Tag-Aware Display

Linked bookmarks' Bookmarks-side tags SHALL be shown as filter chips in the tab.

#### Scenario: Tag filter narrows the list

- **GIVEN** 10 linked bookmarks with 3 distinct Bookmarks-side tags
- **WHEN** user clicks the "legal" tag chip
- **THEN** only bookmarks carrying that tag MUST be shown

### Requirement: Widget Surfaces

The system SHALL render four surfaces following the standard contract.

#### Scenario: Standard four surfaces render

- **WHEN** the Bookmarks widget renders
- **THEN** the system MUST render all four surfaces following the standard contract

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'bookmarks'` SHALL render favicon chip.

#### Scenario: Bookmarks reference renders a favicon chip

- **WHEN** a schema property declares `referenceType: 'bookmarks'`
- **THEN** the system MUST render a favicon chip

### Requirement: Permission Inheritance

The provider SHALL declare `requiresPermission() === null`; Bookmarks' own ACLs apply.

#### Scenario: Bookmarks ACLs govern access

- **WHEN** a user accesses the bookmarks integration
- **THEN** the system MUST defer access control to Bookmarks' own ACLs

