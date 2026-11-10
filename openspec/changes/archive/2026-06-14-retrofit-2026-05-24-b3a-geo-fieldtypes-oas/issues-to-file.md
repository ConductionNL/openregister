# Issues to File — Bucket 3a Retrofit

These four issues were identified as genuinely unimplemented during the
bucket 3a investigation. They must be filed as individual Codeberg issues
on the Conduction/openregister repository.

---

## Issue 1: Implement `recurrence` field type with RFC 5545 RRULE support

**Labels:** `enhancement`, `extended-field-types`
**REQ:** `extended-field-types#EFT-003`

### Summary

The `recurrence` field type is declared in the extended-field-types spec but
has no implementation. `sabre/vobject` (the standard PHP RRULE library) is not
even a direct composer dependency.

### Required work

- Add `sabre/vobject` as a composer dependency.
- Add `recurrence` as a valid schema `type` in `PropertyValidatorHandler::$validTypes`.
- Parse and validate RRULE strings on object write; return HTTP 422 with the
  exact error message from `extended-field-types/spec.md` on parse failure.
- Preserve the literal RRULE string on read (do not normalise).
- Emit `_occurrences` in the response: configurable via
  `?recurrenceOccurrences=N` query param (default 5, max 100), using
  `sabre/vobject` to compute the next N occurrences from `DTSTART`.

### Acceptance criteria (from spec)

- A valid RRULE is stored as-is and returned on GET.
- An invalid RRULE (e.g. `RRULE:FREQ=GARBAGE`) returns 422 with
  `"message": "Invalid RRULE: …"`.
- `?recurrenceOccurrences=3` in a GET request adds
  `"_occurrences": ["2026-06-01", "2026-06-08", "2026-06-15"]` to the response.
- Default (no param) returns 5 occurrences.

---

## Issue 2: Promote `color` to a real field type with per-format validation

**Labels:** `enhancement`, `extended-field-types`
**REQ:** `extended-field-types#EFT-005`

### Summary

`color` appears in `PropertyValidatorHandler::$validStringFormats` as a
recognised string-format name, but no actual validation runs and the `oklch`
colour space is absent. The spec requires per-format regex validation and
specific 422 error messages for each invalid notation.

### Required work

- Add `color` as a valid schema `type` in `PropertyValidatorHandler::$validTypes`.
- Implement per-format validation using the `format` sub-field:
  - `hex` — 6-digit `#RRGGBB` (case-insensitive).
  - `hex-alpha` — 8-digit `#RRGGBBAA`.
  - `rgba` — `rgba(R,G,B,A)` with 0–255 integers and 0.0–1.0 alpha.
  - `oklch` — `oklch(L% C H)` with percentage lightness, numeric chroma,
    and hue in degrees.
- Return HTTP 422 with the exact per-format error messages declared in the spec
  scenarios when validation fails.
- If no `format` is supplied, accept any of the above notations.

### Acceptance criteria (from spec)

- `color` with `format: "hex"` rejects `"#GGHHII"` with the spec error message.
- `color` with `format: "oklch"` accepts `"oklch(53.39% 0.178 262.72)"`.
- `color` with no `format` accepts any valid notation.

---

## Issue 3: Build interactive map UI component with PDOK tile layers

**Labels:** `enhancement`, `geo-metadata-kaart`
**REQ:** `geo-metadata-kaart#GEO-003`

### Summary

No map UI component exists. The backend geo-filtering (`lib/Service/Geo/`) is
in place (GEO-004) but the frontend map view is entirely absent. No mapping
library (Leaflet, MapLibre, OpenLayers) is in `package.json`.

### Required work

- Add a mapping library to `package.json` (Leaflet or MapLibre recommended;
  `@mapbox/leaflet` or `leaflet` + `leaflet.markercluster`).
- Add PDOK BRT Achtergrondkaart as the base tile layer
  (`https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/…`).
- Build a `MapView.vue` component showing objects as markers or polygon
  overlays (depending on geometry type).
- Add a map-view toggle button alongside the existing table/card view
  switchers in the list header.
- Support marker clustering for large datasets
  (`leaflet.markercluster` or equivalent).
- Responsive mobile behaviour: map fills the viewport on small screens.

### Acceptance criteria (from spec)

- Navigating to the object list shows a "Map" tab/button alongside "Table".
- Clicking "Map" renders a PDOK tile-backed leaflet map with markers for each
  visible object that has a geo field.
- Markers cluster when more than ~20 are in view.
- Clicking a marker opens a popup linking to the object detail.
- On a 375 px viewport the map fills the screen.

---

## Issue 4: Implement schema-level geo-fencing with event triggers

**Labels:** `enhancement`, `geo-metadata-kaart`
**REQ:** `geo-metadata-kaart#GEO-010`

### Summary

Geo-fencing is entirely unimplemented. No fence configuration exists on schemas,
no boundary-crossing detection runs on object saves, and none of the required
events (`ObjectEnteredGeoFence`, `ObjectExitedGeoFence`,
`ObjectCreatedInGeoFence`) exist in `lib/Event/`.

### Required work

- Add `x-geo-fence` extension to schema configuration allowing one or more
  named polygon/circle boundary definitions.
- On object create/update (where the object has a geo field): check whether
  the new position crosses any configured fence boundary.
- Dispatch the appropriate event(s):
  - `ObjectCreatedInGeoFence` — new object lands inside a fence.
  - `ObjectEnteredGeoFence` — existing object moves from outside to inside.
  - `ObjectExitedGeoFence` — existing object moves from inside to outside.
- Surface events to n8n and webhooks via the existing `WebhookService`.

### Acceptance criteria (from spec)

- A schema with `x-geo-fence: [{name: "Amsterdam", type: "polygon", coordinates: […]}]`
  fires `ObjectEnteredGeoFence` when an object's location transitions to inside
  the polygon.
- The event payload includes the fence name, object ID, and timestamp.
- The event is forwarded to registered n8n/webhook endpoints.
