---
status: proposed
---

# Integration: Collectives

## Purpose

Link NC Collectives (team wikis) pages to OR objects. Native alternative to the XWiki external integration.

**Standards**: NC Collectives API, CommonMark/Markdown, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## Requirements

### Requirement: Collectives Provider Registration

`CollectivesProvider` registered with id='collectives', group='docs', requiredApp='collectives', storage='link-table'.

### Requirement: Link-Only (No Create)

Integration SHALL support linking existing pages; page creation lives in Collectives.

### Requirement: Markdown Preview in Tab

Tab SHALL render page content via markdown (safe subset) with collapsible overflow.

### Requirement: Detail-Page Surface Renders Inline Content

Unlike other integrations, `CnCollectivesCard` at `surface='detail-page'` SHALL render the most-recent linked page's content inline.

#### Scenario: One linked page renders in detail-page surface

- **GIVEN** an object with one linked Collectives page
- **WHEN** `CnCollectivesCard` renders with `surface='detail-page'`
- **THEN** the page's markdown content MUST be rendered inline
- **AND** a "Read more" link MUST point to the page in Collectives

#### Scenario: Multiple linked pages show tabs

- **GIVEN** an object with 3 linked pages
- **WHEN** `CnCollectivesCard` renders with `surface='detail-page'`
- **THEN** 3 tabs MUST appear — one per page
- **AND** the most-recently-linked page MUST be selected by default

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'collectives'` SHALL render a page-title chip at single-entity surface.

### Requirement: Permission Inheritance

`requiresPermission() === null`; Collectives ACLs apply.
