# Design: extend-faceting-configuration

## Architecture Overview

This change extends the existing faceting system at three layers:

1. **Schema property storage** — `facetable` accepts `true`, `false`, or a config object
2. **FacetHandler (backend)** — Reads config, controls aggregation, sets title/description/order, includes `schemaId` for non-aggregated facets
3. **Frontend (tilburg-woo-ui)** — Reads `schemaId` from facet response, adds `_schema` query param for non-aggregated facets

```
Schema Property
  facetable: true | false | { aggregated, title, description, order }
       ↓
FacetHandler.getFacetableFieldsFromSchemas()
  → normalize config (true → {aggregated: true})
  → pass config per field
       ↓
FacetHandler.transformFacetsToStandardFormat()
  → use config title/description/order
  → for non-aggregated: key by schema+field, include schemaId
       ↓
API Response (facets JSON)
  → each facet entry now MAY include "schema": <id>
       ↓
tilburg-woo-ui con-facets-filters
  → when selecting facet with schema field, add _schema=<id> to query
```

## API Design

### Facet Response Format (extended)

The existing facet entry format gains an optional `schema` field:

**Current format (unchanged for aggregated facets):**
```json
{
  "type": {
    "name": "type",
    "type": "terms",
    "title": "Type",
    "description": "object field: type",
    "queryParameter": "type",
    "source": "object",
    "order": 5,
    "enabled": true,
    "data": {
      "type": "terms",
      "total_count": 3,
      "buckets": [
        { "value": "leverancier", "count": 12, "label": "Leverancier" }
      ]
    }
  }
}
```

**New format for non-aggregated facets:**
```json
{
  "organisatie_type": {
    "name": "type",
    "type": "terms",
    "title": "Organisatie Type",
    "description": "Type of organisation",
    "queryParameter": "type",
    "source": "object",
    "order": 2,
    "enabled": true,
    "schema": 42,
    "data": {
      "type": "terms",
      "total_count": 3,
      "buckets": [
        { "value": "leverancier", "count": 12, "label": "Leverancier" }
      ]
    }
  }
}
```

Key differences for non-aggregated facets:
- `schema` field: integer schema ID (only present when `aggregated: false`)
- Facet key: uses a unique key combining schema context and field name to avoid collisions (e.g., `organisatie_type` instead of `type`)
- `title`: from the faceting config (custom title)
- `order`: from the faceting config

### Schema Property Format (extended)

**Before (boolean only):**
```json
{
  "type": {
    "type": "string",
    "facetable": true
  }
}
```

**After (boolean or config object):**
```json
{
  "type": {
    "type": "string",
    "facetable": {
      "aggregated": false,
      "title": "Organisatie Type",
      "description": "Filter by organisation type",
      "order": 2
    }
  }
}
```

**Config object defaults (when omitted):**
| Field | Default | Notes |
|-------|---------|-------|
| `aggregated` | `true` | Matches current behavior |
| `title` | `null` | Falls back to property title or formatted field name |
| `description` | `null` | Falls back to auto-generated description |
| `order` | `null` | Falls back to auto-incremented order |

## Database Changes

No database migration needed. The `facetable` value is stored as part of the schema's `properties` JSON column. The JSON column already supports arbitrary values per property key — changing from `true` to an object requires no schema change.

## Nextcloud Integration

- **Controllers**: No changes — the API response shape is extended, not restructured
- **Services**:
  - `FacetHandler.php` — Core changes to `getFacetableFieldsFromSchemas()` and `transformFacetsToStandardFormat()`
- **Mappers/Entities**: No changes — `Schema::getProperties()` already returns raw JSON
- **Events/Hooks**: No changes

## File Structure

### OpenRegister (backend + admin UI)
```
lib/
  Service/
    Object/
      FacetHandler.php          ← MODIFY: config parsing, aggregation logic, response transform
src/
  modals/
    schema/
      EditSchemaProperty.vue    ← MODIFY: faceting config UI fields
```

### Tilburg WOO UI (frontend)
```
src/
  molecules/
    con-facets-filters/
      con-facets-filters.js     ← MODIFY: _schema param for non-aggregated facets
```

## Security Considerations

- No new API endpoints or authentication changes
- The `_schema` query parameter is already supported by the backend filter logic
- Faceting config is set by admin users through the schema editor (authenticated Nextcloud UI)
- No user-supplied input flows into SQL — facet config only affects response transformation

## NL Design System

No NL Design System changes needed. The schema editor uses standard Nextcloud Vue components (`NcCheckboxRadioSwitch`, `NcTextField`, `NcInputField`). The frontend facet display in tilburg-woo-ui already uses Utrecht components and is unaffected by this change.

## Trade-offs

### Decision 1: Facet key for non-aggregated facets

**Chosen**: Use a sanitized key combining schema slug/ID and field name (e.g., `organisatie_type`) to avoid collisions when the same property name exists in multiple schemas.

**Alternative considered**: Keep the field name as the key and let the frontend disambiguate. Rejected because multiple non-aggregated facets with the same key would overwrite each other in the response object.

### Decision 2: Config stored inline vs. separate table

**Chosen**: Store faceting config inline in the property JSON (`facetable: {...}`).

**Alternative considered**: Separate `facet_config` table. Rejected because it adds migration complexity, requires joins, and the config is tightly coupled to the property — inline storage is simpler and sufficient.

### Decision 3: Aggregation control at property level vs. schema level

**Chosen**: Per-property `aggregated` flag. Each property independently controls whether its facet is aggregated across schemas.

**Alternative considered**: Schema-level "isolate all facets" flag. Rejected because it's too coarse — you might want `status` aggregated but `type` non-aggregated within the same schema.

### Decision 4: Frontend _schema parameter approach

**Chosen**: Backend includes `schema` (ID) in facet response; frontend adds `_schema=<id>` to query params when selecting non-aggregated facets.

**Alternative considered**: Complex filter query syntax like `type[_schema=42]=leverancier`. Rejected because the backend already supports `_schema` as a standalone filter, and non-aggregated facets by definition return only one schema type.
