# Retrofit — Bucket 3a investigation: geo / field-types / OAS (5 REQs)

Bucket 3a collects requirements the coverage scanner flagged with **no
annotated implementation found**. This change records the investigation
outcome for 5 such REQs across three capabilities (geo-metadata-kaart,
extended-field-types, oas-validation), classifying each as
IMPLEMENTED / PARTIAL / MISSING and annotating the one that turned out to
already ship.

Source: `/tmp/or-scan/b3a-geo-fieldtypes-oas.json` (scanner batch, 5 REQs).
This is an annotations-only / paper-trail change — no behaviour is added.
Missing REQs are listed below for GitHub issue filing.

## Per-REQ classification

| REQ | Title | Classification | Evidence |
|-----|-------|----------------|----------|
| `oas-validation#API-46` | Error responses include problem details (RFC 7807) | **IMPLEMENTED** | `lib/Service/Oas/ProblemDetailsBuilder.php` builds the full RFC 7807 shape (`type`/`title`/`status`/`detail`/`instance` + extensions). `lib/Service/Resources/BaseOas.json` `Error` schema declares every RFC 7807 field plus legacy `error`/`code` aliases. Wired via `OasValidationMiddleware` (sends `application/problem+json`) and `OasRequestValidator`. Exceeds the spec, which only marked the RFC 7807 fields as a "future enhancement SHOULD". |
| `extended-field-types#EFT-005` | `color` type accepts hex / rgba / oklch + validates per declared format | **PARTIAL** | `color` is NOT a schema `type` (not in `PropertyValidatorHandler::$validTypes`). It exists only as accepted string-**format** names in `$validStringFormats` (`color`, `color-hex`, `color-hex-alpha`, `color-rgb`, `color-rgba`, `color-hsl`, `color-hsla`). `SchemaService::detectStringFormat()` infers `color` from a 6-digit-hex heuristic only. There is **no** per-format validation regex, **no** `oklch`, and **no** per-format 422 error messages the REQ mandates. Recognised in name only. |
| `extended-field-types#EFT-003` | `recurrence` type stores RFC 5545 RRULE + emits upcoming occurrences | **MISSING** | No `recurrence` schema type. No `sabre/vobject` RRULE parsing (`sabre/vobject` is not even a composer dependency — only a transitive `sabre/dav` advisory pin in `composer.lock`). No `_occurrences` enrichment, no `?recurrenceOccurrences=N` handling. `SchemaTypeConverter` only dispatches the 6 JSON-Schema primitives. |
| `geo-metadata-kaart#GEO-003` | Map visualization component with PDOK tile layers | **MISSING** | No map UI. `leaflet` / `maplibre` / `openlayers` / `mapbox` / `markercluster` absent from `package.json`. No `pdok` / `achtergrondkaart` / `L.map` reference anywhere in `src/`. No map view toggle, no marker clustering, no polygon overlay component. (Backend geo-filtering under `lib/Service/Geo/` exists — that covers GEO-004, not GEO-003.) |
| `geo-metadata-kaart#GEO-010` | Geo-fencing with event triggers | **MISSING** | No geo-fence schema configuration. No `ObjectEnteredGeoFence` / `ObjectCreatedInGeoFence` events (not in `lib/Event/`). No boundary-crossing detection. Entirely unimplemented. |

## Summary

- **IMPLEMENTED: 1** — API-46
- **PARTIAL: 1** — EFT-005
- **MISSING: 3** — EFT-003, GEO-003, GEO-010

## Affected code units (annotated)

- `lib/Service/Oas/ProblemDetailsBuilder.php` — added scenario-level `@spec`
  pointer to the API-46 / RFC 7807 scenario in
  `openspec/changes/oas-validation/specs/oas-validation/spec.md`. The class
  already carried a `tasks.md`-level `@spec`; the scanner missed it because
  no annotation named the scenario. No behaviour change.

## Gaps for issue filing

The following REQs are genuinely unimplemented (or only nominally present)
and should be tracked as GitHub issues — they are aspirational parts of
feature-rich specs that do not yet ship:

1. **`extended-field-types#EFT-003`** — implement the `recurrence` field
   type: add `sabre/vobject` as a dependency, parse/validate RRULE on write
   (HTTP 422 on parse error with the exact spec message), preserve the
   literal RRULE on read, and emit `_occurrences` (configurable
   `?recurrenceOccurrences=N`, default 5, max 100).
2. **`extended-field-types#EFT-005`** — promote `color` to a real field type
   with per-declared-`format` validation (`hex` 6/8-digit, `rgba` 4-component,
   `oklch`), returning the exact 422 messages in the spec scenarios. Today
   only string-format *names* are whitelisted; no notation validation runs and
   `oklch` is absent.
3. **`geo-metadata-kaart#GEO-003`** — build the interactive map UI component
   (PDOK BRT Achtergrondkaart base layer, marker clustering, polygon overlays,
   map-view toggle alongside table/card, responsive mobile behaviour).
4. **`geo-metadata-kaart#GEO-010`** — implement schema-level geo-fence
   configuration plus `ObjectEnteredGeoFence` / `ObjectCreatedInGeoFence`
   (and exit) events surfaced to n8n/webhooks.
