---
status: proposed
---

# Integration: Bookmarks

## Purpose

Link NC Bookmarks (URLs) to OR objects through the registry with tag-aware display.

**Standards**: NC Bookmarks API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Bookmarks Provider Registration

`BookmarksProvider` registered with id='bookmarks', group='docs', requiredApp='bookmarks', storage='link-table'.

### Requirement: Add URL Flow Delegates Scraping

"Add URL" MUST call the NC Bookmarks create endpoint to extract title/favicon; OR MUST NOT re-implement scraping.

### Requirement: Tag-Aware Display

Linked bookmarks' Bookmarks-side tags SHALL be shown as filter chips in the tab.

#### Scenario: Tag filter narrows the list

- **GIVEN** 10 linked bookmarks with 3 distinct Bookmarks-side tags
- **WHEN** user clicks the "legal" tag chip
- **THEN** only bookmarks carrying that tag MUST be shown

### Requirement: Widget Surfaces

Four surfaces, standard contract.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'bookmarks'` SHALL render favicon chip.

### Requirement: Permission Inheritance

`requiresPermission() === null`; Bookmarks' own ACLs apply.
