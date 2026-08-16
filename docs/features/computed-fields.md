# Computed Fields

## Overview

Computed fields are schema properties whose values are derived automatically from Twig expressions evaluated against object data, cross-referenced objects, and aggregation functions. This eliminates redundant data entry, ensures consistency of derived values (full names, totals, expiry dates), and brings spreadsheet-like formula power to OpenRegister without requiring external workflow engines for simple calculations.

## Configuration

Computed fields are defined in the schema as properties with `computed: true` and a `formula` expression:

```json
{
  "properties": {
    "voornaam": { "type": "string" },
    "achternaam": { "type": "string" },
    "volledige_naam": {
      "type": "string",
      "computed": true,
      "formula": "{{ voornaam }} {{ achternaam }}",
      "evaluateOn": "save"
    },
    "btw_bedrag": {
      "type": "number",
      "computed": true,
      "formula": "{{ netto_bedrag * 0.21 | round(2) }}",
      "evaluateOn": "save"
    },
    "vervaldatum": {
      "type": "string",
      "format": "date",
      "computed": true,
      "formula": "{{ aanmaakdatum | date_modify('+1 year') | date('Y-m-d') }}",
      "evaluateOn": "save"
    }
  }
}
```

## Evaluation Modes

| Mode | Field | Description |
|------|-------|-------------|
| `save` (default) | `evaluateOn: "save"` | Evaluated at save time; stored in the database |
| `read` | `evaluateOn: "read"` | Evaluated at read time; never stored; always fresh |
| `on-demand` | `evaluateOn: "on-demand"` | Only evaluated when explicitly requested via `?_computeFields=true` |

**Save mode** is the most efficient — the computed value is stored like any other field and is available in search/filter/facet operations.

**Read mode** is appropriate for values that depend on the current time (e.g., `days_overdue`), which would become stale if stored.

**On-demand mode** is for expensive computations (cross-object aggregations) that should not run on every request.

## Twig Formula Context

The formula has access to all object properties as direct variables:

| Variable | Type | Description |
|----------|------|-------------|
| `{property_name}` | any | Direct access to object properties |
| `_uuid` | string | Object UUID |
| `_created` | DateTime | Creation timestamp |
| `_updated` | DateTime | Last updated timestamp |
| `_owner` | string | Owner user ID |
| `now` | DateTime | Current UTC datetime (for read-mode formulas) |

### Cross-Field References

Properties can reference other properties within the same object:

```json
"formula": "{{ prijs * aantal | round(2) }}"
```

Computed fields can reference other computed fields (evaluated in dependency order).

### Cross-Object References

Properties with UUID references can traverse to related objects:

```json
"klant_naam": {
  "type": "string",
  "computed": true,
  "formula": "{{ klant.voornaam }} {{ klant.achternaam }}",
  "evaluateOn": "read"
}
```

Here `klant` is a UUID reference property; the Twig context resolves it to the full referenced object automatically.

### Aggregation Functions

Custom Twig functions for aggregations:

| Function | Example | Description |
|----------|---------|-------------|
| `sum(field)` | `{{ sum(regels, 'bedrag') }}` | Sum a field across related objects |
| `count(field)` | `{{ count(bijlagen) }}` | Count items in a relation |
| `avg(field)` | `{{ avg(beoordelingen, 'score') }}` | Average a numeric field |
| `max(field)` | `{{ max(deadlines, 'datum') }}` | Maximum value |
| `min(field)` | `{{ min(deadlines, 'datum') }}` | Minimum value |

### Standard Twig Filters Available

All standard Twig filters are available: `date`, `date_modify`, `upper`, `lower`, `trim`, `replace`, `split`, `join`, `round`, `number_format`, `slice`, `first`, `last`, `length`, `default`, `escape`, `raw`, conditional expressions, and more.

## Validation

Computed fields cannot be set directly via the API — attempts to write to a computed property return a validation error. The value is always derived from the formula.

When a formula produces an error (e.g., division by zero, missing referenced object), the field value is set to `null` and a warning is logged. The object is still saved.

## Formula Editing

The OpenRegister admin UI provides a formula editor with:

- Syntax highlighting for Twig expressions
- Autocomplete for property names from the current schema
- Live preview using sample object data
- Dependency graph visualization (which fields depend on which)

## API

Computed fields appear transparently in all API responses alongside stored fields. To request on-demand computation:

```
GET /api/objects/{register}/{schema}/{id}?_computeFields=true
```

To recompute all computed fields on all objects in a schema (e.g., after a formula change):

```
POST /api/schemas/{id}/recompute
```

This schedules a background job that re-evaluates all save-mode computed fields.

## Related Features

- [Registers & Schemas](registers-and-schemas.md) — computed fields are configured on schema properties
- [Object Storage & Lifecycle](object-storage.md) — computed values stored alongside object data
- [Search, Filtering & Faceting](search-and-faceting.md) — save-mode computed fields are searchable and facetable
- [Workflow Automation](workflow-automation.md) — for complex derivations, schema hooks trigger external workflow engines
