# Design: Integration — Photos

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths.

## Approach

`PhotoService` filters the object's file links to image MIME types and enriches with EXIF + GPS where available. Single storage table, two views (files vs photos) of the same underlying relation.

## Architecture Decisions

### AD-1: Photos is a filtered view of Files, not a separate link table

**Decision**: Reuse the file link table; `PhotoService` filters by MIME type at query time and adds EXIF metadata. A photo-specific metadata column lives on the file link when relevant.

**Why**: A user who uploads a photo via the Photos tab should see it in the Files tab too — they're the same file. Two separate tables would cause confusion and double-counting.

**Trade-off**: Files and Photos tabs can feel like overlap. Acceptable — the UI difference (grid vs list, EXIF vs generic metadata) justifies both.

### AD-2: EXIF extraction is lazy

**Decision**: EXIF pulled on first view per file, cached on the link row. Not pre-extracted at link time.

**Why**: Many users link photos without ever viewing EXIF. Pre-extraction wastes cycles.

## Files Affected

### Backend (new)
- `PhotoService`, `PhotosController`, `PhotosProvider`, EXIF extractor utility, migration to add `exif_metadata` column to `openregister_file_links`, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnPhotosTab/*` (grid + lightbox), `CnPhotosCard/*` (strip/chip), `src/integrations/builtin/photos.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| Very large images slow grid | Nextcloud preview pipeline provides thumbnails; integration uses thumbnail endpoints |
| HEIC/non-browser formats | Fall back to preview endpoint; show format warning if no preview available |
| EXIF privacy (GPS from phone photos) | Admin-toggle to strip GPS on link; off by default (opt-in) |
