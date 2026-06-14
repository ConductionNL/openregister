---
status: done
---

# integration-photos Specification

## Purpose
TBD - created by archiving change integration-photos. Update Purpose after archive.
## Requirements
### Requirement: Photos Provider Registration

The system SHALL register `PhotosProvider` with id='photos', group='docs', requiredApp='photos', storage='link-table'.

#### Scenario: Provider registered

- **WHEN** the integration registry is built
- **THEN** the system MUST register `PhotosProvider` with id='photos', group='docs', requiredApp='photos', storage='link-table'

### Requirement: Photos is Filtered Files View

Photos SHALL share the `openregister_file_links` table with Files; filtering is by MIME type at query time.

#### Scenario: Photo visible in both tabs

- **GIVEN** an object with a linked JPEG file
- **WHEN** user opens both Files tab and Photos tab
- **THEN** the same file MUST appear in both

### Requirement: Lazy EXIF

EXIF SHALL be extracted on first photo view per file and cached in the link row.

#### Scenario: EXIF extracted on first view

- **WHEN** a photo is viewed for the first time
- **THEN** the system MUST extract its EXIF and cache it in the link row

### Requirement: Optional GPS Stripping

Admin setting SHALL allow stripping GPS data from EXIF at link time. Default OFF (opt-in).

#### Scenario: GPS strip setting removes GPS data

- **GIVEN** GPS-strip setting enabled
- **WHEN** a photo is linked to an object
- **THEN** the stored `exif_metadata` MUST NOT contain GPS coordinates
- **AND** the original file MUST NOT be modified

### Requirement: Widget Surfaces

The system SHALL render the standard four surfaces with grid/strip appropriate to each.

#### Scenario: Surfaces rendered

- **WHEN** the Photos integration renders
- **THEN** the system MUST provide the standard four surfaces with grid/strip appropriate to each

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'photos'` SHALL render thumbnail chip.

#### Scenario: Reference property renders thumbnail chip

- **WHEN** a property with `referenceType: 'photos'` is rendered
- **THEN** the system MUST render the thumbnail chip

### Requirement: Permission Inheritance

The system SHALL expose `requiresPermission() === null`; file permissions apply.

#### Scenario: Permission inherited from files

- **WHEN** `requiresPermission()` is evaluated for the Photos provider
- **THEN** it MUST return `null` so that file permissions apply

