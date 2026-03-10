# Proposal: extend-faceting-configuration

## Summary
Extend the OpenRegister faceting system to support per-property configuration (title, description, order, aggregation control) instead of just a boolean flag, and propagate schema context to the frontend for non-aggregated facets. This eliminates the need for data duplication caused by renaming properties (e.g., `type` → `organisatieType`) to avoid unwanted cross-schema facet aggregation.

## Motivation
Multiple schemas share property names like `type`. When facets are aggregated across schemas, values from different contexts get merged into a single facet (e.g., organisation types mixed with product types). The current workaround is to rename properties per schema (e.g., `organisatieType`) and copy data from the original `type` field, causing **data duplication**.

By allowing properties to opt out of aggregation and define custom facet labels, schemas can keep their natural property names (`type`) while controlling how they appear as facets. This removes the need for duplicate properties and simplifies data management.

## Affected Projects
- [x] Project: `openregister` — Extend facetable config on properties, update FacetHandler to respect config, update schema editor UI
- [x] Project: `tilburg-woo-ui` — Update search page to add `_schema` query parameter when selecting non-aggregated facets

## Scope
### In Scope
- Extend `facetable` from `boolean` to `boolean | FacetConfig` on schema properties
- `FacetConfig` shape: `{ aggregated: bool, title: string, description: string, order: int }`
- Backward compatibility: `facetable: true` behaves exactly as today (aggregated, auto-generated title)
- Update `FacetHandler.getFacetableFields()` and facet response transformation to use config values
- Non-aggregated facets include `schemaId` in the API facet response so the frontend can scope queries
- Update `EditSchemaProperty.vue` to show faceting config fields (title, description, order, aggregated toggle) conditionally when facetable is enabled
- Update `tilburg-woo-ui` `con-facets-filters` to add `_schema=<schemaId>` to query params when a non-aggregated facet is selected
- Visual testing of the schema editor UI changes with Playwright

### Out of Scope
- Changes to backend `_schema` filter logic (already supported)
- Facet type configuration (terms, date_histogram, range — remains auto-detected)
- Changes to the facet caching strategy
- Migration of existing schemas (they keep working with `facetable: true`)

## Approach

### Backend (OpenRegister)
1. **Property schema**: Accept `facetable` as `true`, `false`, or `{ aggregated: true, title: "...", description: "...", order: 0 }`. Normalize in `FacetHandler.getFacetableFields()` — treat `true` as `{ aggregated: true }`.
2. **FacetHandler response transformation**: When building the API facet response, use config values for `title`, `description`, `order` instead of auto-generated defaults. For non-aggregated facets, include a `schema` field (the schema ID) in the facet metadata so the frontend knows to scope queries.
3. **Non-aggregated facet logic**: In `FacetHandler`, when aggregating facets across schemas, skip merging buckets for properties where `aggregated: false`. Instead, emit them as separate facets keyed by schema context (e.g., use the custom title as the facet key).
4. **Schema editor UI**: In `EditSchemaProperty.vue`, replace the simple facetable checkbox with a toggle that reveals config fields when enabled.

### Frontend (Tilburg WOO UI)
5. **Facet selection**: In `con-facets-filters.js`, when a facet includes a `schema` field (non-aggregated), append `_schema=<schemaId>` to the query parameters when the user selects that facet.
6. **Active filters**: Ensure `con-active-filters.js` correctly displays and removes `_schema` parameters.

### Key Files
| File | Change |
|------|--------|
| `openregister/lib/Service/Object/FacetHandler.php` | Config parsing, aggregation logic, response transformation |
| `openregister/src/modals/schema/EditSchemaProperty.vue` | Faceting config UI fields |
| `tilburg-woo-ui/src/molecules/con-facets-filters/con-facets-filters.js` | `_schema` parameter for non-aggregated facets |
| `tilburg-woo-ui/src/stores/publications.store.js` | Handle `_schema` in query building |

## Cross-Project Dependencies
- **tilburg-woo-ui** depends on the updated OpenRegister facet API response format (new `schema` field on non-aggregated facets)
- Backend changes should be deployed first; frontend is additive and gracefully ignores the new field if not present

## Rollback Strategy
- **Backend**: `facetable: true` remains the default and existing behavior is unchanged. Reverting means removing config parsing — properties with object configs would fall back to being treated as `facetable: true`.
- **Frontend**: The `_schema` parameter addition is conditional on the facet metadata. If the backend is reverted, the frontend simply never sees the `schema` field and behaves as before.
- **No database migration**: The facet config is stored inline in the schema property JSON, so no schema changes to revert.

## Open Questions
None — all requirements have been clarified.
