# Integration: Photos

## Problem

Image attachments (site photos, document scans, evidence photos) are currently files in the Files integration, rendered as generic file items. A first-class photo integration would surface thumbnail grids, metadata (EXIF date, GPS), and photo-specific UX. Today this leaf is **stub** per the 2026-05-24 registry audit — `PhotosProvider.php` is a 137-line copy-paste of the MarkerLookupTrait template with no `OCA\Photos\…` imports and `getLinkedItems()` returns `[]`. This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Procest and Zaakafhandelapp have no working integration path and reinvent photo-attachment surfacing locally.

## Context

- **Audit bucket**: stub (2026-05-24)
- **Current backend**: 137-line MarkerLookupTrait template, no `OCA\Photos\…` imports; `getLinkedItems()` returns `[]`
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget; backend returns `[]` so the tab is empty
- **Target NC class(es)**: `OCA\Photos\Service\AlbumService`
- **Storage strategy**: `link-table` (reuses file links, filters at provider level to image MIME types) — decided here because photos need additional metadata (EXIF) not present on generic file links
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)

## Proposed Solution

`PhotoService` + `PhotosController` + `PhotosProvider` + `CnPhotosTab` + `CnPhotosCard`. Tab shows thumbnail grid with EXIF-sorted display. Widget on detail-page renders a photo strip inline. Provider imports `OCA\Photos\Service\AlbumService` for the linked-album query and falls back to `IntegrationHealth::missingApp('photos')` when NC Photos is not installed.

## Scope

**In scope:** Backend service (filters Files to images, extracts EXIF), link-table approach reusing file links, provider, tab with grid/lightbox, widget with strip/carousel, registration, tests, nl+en.

**Out of scope:** Photo editing (Photos app owns); album management beyond reading Photos albums; face recognition.

## Acceptance criteria

- [ ] Photos tab shows image thumbnails in a grid
- [ ] Clicking opens lightbox with EXIF metadata
- [ ] Widget renders photo strip on detail-page
- [ ] Reference-property `referenceType: 'photos'` renders photo chip (thumbnail + filename)
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\Photos\Service\AlbumService` import for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('photos')` when NC Photos absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` keeps generic `leaf()` shell with notes — bespoke Tab + Widget components are OUT of this change's scope; file follow-up if needed
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
