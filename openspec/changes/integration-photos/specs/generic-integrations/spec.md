---
status: proposed
---

# Integration: Photos

## Purpose

Surface image attachments on OR objects with photo-specific UX (grid, lightbox, EXIF) as a filtered view of the Files integration.

**Standards**: EXIF 2.3, NC Photos API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Photos Provider Registration

`PhotosProvider` registered with id='photos', group='docs', requiredApp='photos', storage='link-table'.

### Requirement: Photos is Filtered Files View

Photos SHALL share the `openregister_file_links` table with Files; filtering is by MIME type at query time.

#### Scenario: Photo visible in both tabs

- **GIVEN** an object with a linked JPEG file
- **WHEN** user opens both Files tab and Photos tab
- **THEN** the same file MUST appear in both

### Requirement: Lazy EXIF

EXIF SHALL be extracted on first photo view per file and cached in the link row.

### Requirement: Optional GPS Stripping

Admin setting SHALL allow stripping GPS data from EXIF at link time. Default OFF (opt-in).

#### Scenario: GPS strip setting removes GPS data

- **GIVEN** GPS-strip setting enabled
- **WHEN** a photo is linked to an object
- **THEN** the stored `exif_metadata` MUST NOT contain GPS coordinates
- **AND** the original file MUST NOT be modified

### Requirement: Widget Surfaces

Per umbrella AD-6/AD-18, the widget SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`) with grid/strip appropriate to each.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'photos'` SHALL render thumbnail chip.

### Requirement: Permission Inheritance

`requiresPermission() === null`; file permissions apply.

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying photo in NC Photos is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: HEIC format without preview support

- **GIVEN** a linked photo in HEIC format and the server has no HEIC preview provider
- **WHEN** `CnPhotosTab` grid renders
- **THEN** a format-unsupported placeholder MUST be shown with the filename
- **AND** download MUST still work
