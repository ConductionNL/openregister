---
title: Schema Import from Standards
sidebar_position: 31
description: Import register schemas from external standards — Schema.org types and GGM objecttypes — with datatype mapping, pre-filled JSON-LD vocabulary, import provenance, and guarded update-from-source.
keywords:
  - Open Register
  - Schema Import
  - Schema.org
  - GGM
  - Gemeentelijk Gegevensmodel
  - JSON-LD
---

# Schema Import from Standards

Open Register can build a register schema **from an external standard**
instead of hand-writing JSON Schema. Two dialects ship today —
[Schema.org](https://schema.org/) types and **GGM** (the VNG
*Gemeentelijk Gegevensmodel*) objecttypes — and the importer interface is
pluggable, so DCAT/SKOS/ZGW dialects are follow-up additions.

Both vocabularies ship as **bundled, versioned snapshots** inside the app
(`lib/Resources/schemaorg/`, `lib/Resources/ggm/`). Imports are therefore
deterministic and require **no network egress** at import time.

## Schema.org import

Reference a type by IRI (`https://schema.org/Person`) or bare name
(`Person`). The importer:

- maps each Schema.org property to a JSON Schema property, carrying its
  description over;
- maps Schema.org datatypes to JSON Schema types/formats (see the table
  below);
- imports the type's **direct** properties by default; ancestor
  (inherited) properties are added only when `includeAncestors` is set;
- accepts an optional `propertySubset` (Schema.org types are large —
  `Person` alone has dozens of properties) and reports any requested
  property that does not exist on the type;
- **pre-fills** the schema's `configuration.jsonld` block (`@vocab`,
  class IRI, per-property term map) per the
  [JSON-LD output](./json-ld.md) contract — so JSON-LD output of the
  schema's objects is Schema.org-conformant with zero manual mapping.

| Schema.org range | JSON Schema |
|---|---|
| `Text` | `string` |
| `Number` / `Float` | `number` |
| `Integer` | `integer` |
| `Boolean` | `boolean` |
| `Date` | `string` / `format: date` |
| `DateTime` | `string` / `format: date-time` |
| `Time` | `string` / `format: time` |
| `URL` | `string` / `format: uri` |
| object-typed range (a class) | `string` / `format: uri` (a reference, never a recursive import) |
| multi-type range | the most permissive member (string wins) |

```bash
# Discover importable types
GET /api/schema-import/schema.org/types?q=person

# Import Person with a property subset into a register
POST /api/schema-import/schema.org
{ "reference": "Person",
  "propertySubset": ["givenName", "familyName", "email", "birthDate"],
  "targetRegister": 42 }
```

An unknown type reference returns **404** naming the reference.

## GGM import

Reference a GGM objecttype by id or Dutch name. The importer preserves
the Dutch metadata verbatim (objecttype name/definition → schema
title/description; each attribuutsoort name/definition → property
title/description), maps attribute types per the table below, turns a
referentielijst (with values present) into `enum`, and renders relations
as **single reference properties** (never recursive imports).

| GGM type | JSON Schema |
|---|---|
| tekst | `string` |
| geheel getal | `integer` |
| decimaal | `number` |
| boolean | `boolean` |
| datum | `string` / `format: date` |
| datumtijd | `string` / `format: date-time` |
| relatie | `string` / `format: uri` (reference) |

GGM can also be imported from an **uploaded GGM export** (admin-only),
normalised through the same intermediate that the bundled snapshot uses;
provenance then points at the upload. The bundled snapshot is produced by
`tools/generate-ggm-snapshot.php` from a published GGM release.

## Dialect detection on upload

The existing `POST /api/schemas/upload` (and `PUT /api/schemas/{id}/upload`)
ingestion path accepts an optional `dialect` parameter
(`json-schema` | `openapi` | `schema.org` | `ggm`). When absent, the
dialect is **detected** from unambiguous structural markers:

- `$schema` / a JSON-Schema shape → `json-schema`
- `openapi` + `components` → `openapi`
- `@context` referencing schema.org → `schema.org`
- GGM export markers → `ggm`

An explicit `dialect` always wins. **Input matching no dialect now fails
with HTTP 422** (listing the supported dialects) instead of being
silently mis-ingested as JSON Schema. Existing JSON Schema and OpenAPI
uploads behave exactly as before.

## Provenance and update-from-source

Every standards-imported schema records, in
`configuration.importSource`, the dialect, source identifier, snapshot
version, import timestamp, and the **imported baseline** property
definitions. Re-importing the same source updates the schema with a
three-way merge that never silently destroys local work:

- `POST /api/schemas/{id}/reimport` returns a **diff preview**
  (added / removed / changed / kept-local / conflicts) by default;
- pass `apply: true` to persist;
- **locally added** properties are kept; **locally modified** properties
  whose source is unchanged are kept; a property changed **both** locally
  and in the source is reported as a **conflict** and is not overwritten
  until confirmed per-property via `resolveConflicts: ["name", ...]`;
- the apply flows through the standard schema-update path, so the
  [schema versioning](../../openspec/specs/schema-migration/spec.md)
  version bump / changelog / breaking-change gate apply as for any other
  update.

## Authorization

All import endpoints are **admin-gated** (the same authority as creating a
schema): the Nextcloud SecurityMiddleware rejects non-admins before the
controller runs.
