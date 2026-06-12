# Integration: Photos

## Problem

Image attachments (site photos, document scans, evidence photos) are currently files in the Files integration, rendered as generic file items. A first-class photo integration would surface thumbnail grids, metadata (EXIF date, GPS), and photo-specific UX.

## Context

- **Backend:** greenfield but builds on Files — `PhotoService` filters the object's linked files to image types and adds metadata extraction
- **Required NC app:** `photos`
- **Storage:** `link-table` (reuses file links, filters at provider level to image MIME types) — decided here because photos need additional metadata (EXIF) not present on generic file links
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`PhotoService` + `PhotosController` + `PhotosProvider` + `CnPhotosTab` + `CnPhotosCard`. Tab shows thumbnail grid with EXIF-sorted display. Widget on detail-page renders a photo strip inline.

## Scope

**In scope:** Backend service (filters Files to images, extracts EXIF), link-table approach reusing file links, provider, tab with grid/lightbox, widget with strip/carousel, registration, tests, nl+en.

**Out of scope:** Photo editing (Photos app owns); album management beyond reading Photos albums; face recognition.

## Acceptance criteria

- [ ] Photos tab shows image thumbnails in a grid
- [ ] Clicking opens lightbox with EXIF metadata
- [ ] Widget renders photo strip on detail-page
- [ ] Reference-property `referenceType: 'photos'` renders photo chip (thumbnail + filename)
- [ ] Parity gate passes; nl+en done
