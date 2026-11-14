---
title: Linked Data / JSON-LD
sidebar_position: 30
description: Opt-in read-side JSON-LD serialization of register objects, with schema-derived contexts, canonical @id, and dereferenceable context documents.
keywords:
  - Open Register
  - JSON-LD
  - Linked Data
  - Schema.org
  - content negotiation
---

# Linked Data / JSON-LD

Open Register can serve register objects as
[JSON-LD 1.1](https://www.w3.org/TR/json-ld11/) — the W3C standard for
expressing Linked Data in JSON. JSON-LD output is **opt-in** and
**read-side only**: the default `application/json` representation is
never changed, and write endpoints are untouched.

## Content negotiation

Add `Accept: application/ld+json` to a request on either object read
endpoint to receive a JSON-LD representation:

| Endpoint | Route |
|---|---|
| Single object | `GET /api/objects/{register}/{schema}/{id}` |
| Object collection | `GET /api/objects/{register}/{schema}` |

JSON-LD responses carry `Content-Type: application/ld+json` and
`Vary: Accept`. When the `Accept` header is absent, is
`application/json`, or matches only via a wildcard (`*/*`,
`application/*`), the response is the existing plain-JSON
representation, byte-for-byte (including the `@self` envelope).

Access control is identical across both representations — the JSON-LD
serializer wraps the *already-rendered* object, so RBAC, multitenancy,
the published predicate, and field-level security all keep applying.
There is no second data path.

### Single object

```http
GET /api/objects/personen/persoon/550e8400-… HTTP/1.1
Accept: application/ld+json
```

```jsonc
{
  "@context": "https://nc.example.org/index.php/apps/openregister/api/contexts/personen/persoon",
  "@id": "https://nc.example.org/index.php/apps/openregister/api/objects/personen/persoon/550e8400-…",
  "@type": "persoon",
  "name": "Jansen",
  "birthDate": "1980-04-01",
  "or:register": "personen",
  "or:schema": "persoon",
  "or:urn": "urn:gemeente-a:openregister:personen:persoon:550e8400-…"
}
```

### Collection

A collection response is a single document with one top-level
`@context` and the page's objects as nodes in `@graph` (each with its
own `@id`/`@type`, no repeated `@context`). Pagination metadata is
expressed with `or:` terms so the document stays valid JSON-LD:

```jsonc
{
  "@context": "https://nc.example.org/.../api/contexts/personen/persoon",
  "@graph": [ { "@id": "…", "@type": "persoon", "name": "Jansen" }, … ],
  "or:total": 42,
  "or:page": 1,
  "or:next": "https://nc.example.org/.../api/objects/personen/persoon?_page=2"
}
```

## `@id` and URN identity

Each object's `@id` is its canonical, dereferenceable URI
(`ObjectEntity.uri`). When an object has no stored `uri`, `@id` falls
back to the absolute URL of its `objects#show` route. The object's
[URN](./urn-resource-addressing) is exposed as the `or:urn` term — not
as `@id`, because `@id` is meant to be dereferenceable.

The `@self` envelope is **lifted** into `or:`-prefixed terms
(`or:register`, `or:schema`, `or:urn`, …) and the `@self` key itself
never appears in JSON-LD output. Any object data key that begins with
`@` is escaped under the `or:raw#` prefix to avoid colliding with
JSON-LD keywords.

## The `@context` and the mapping block

The `@context` value references a per-schema context document. The
context is derived mechanically from the schema's `properties`
(JSON Schema):

- Every property gets a term.
- JSON-Schema `format` hints become `@type` coercions:
  `date` → `xsd:date`, `date-time` → `xsd:dateTime`, `uri` → `@id`.
- Relation properties (referencing other objects) are declared
  `"@type": "@id"` so related UUIDs/URIs read as node references.
- The `or:` prefix (`https://openregister.app/ns#`) is always defined
  for OpenRegister metadata terms.

Every schema therefore produces **valid JSON-LD with zero
configuration** — unmapped properties resolve to fragment terms in the
schema's own context document.

### Opting into Schema.org (or any vocabulary)

To align a schema with an external vocabulary such as
[Schema.org](https://schema.org/), add a `jsonld` block to the schema's
existing `configuration` JSON (round-tripped by the schemas API — no
migration needed):

```jsonc
{
  "jsonld": {
    "@vocab": "https://schema.org/",
    "type": "https://schema.org/Person",
    "properties": {
      "name": "https://schema.org/name",
      "birthDate": "https://schema.org/birthDate"
    }
  }
}
```

- `@vocab` — the vocabulary base IRI.
- `type` — the class IRI used as the object's `@type`.
- `properties` — a map of property name → term IRI.

Mapped properties use the declared IRIs; unmapped properties keep their
zero-config defaults; without a `type`, `@type` defaults to the schema
slug. The mapping is **validated on schema save**: term values must be
absolute IRIs or compact terms resolvable against the declared
`@vocab`. An invalid mapping is rejected with `400` and the stored
configuration is left unchanged.

## Context document endpoints

The `@context` URLs are real, dereferenceable documents:

| Endpoint | Returns |
|---|---|
| `GET /api/contexts/{register}` | The register-wide context document |
| `GET /api/contexts/{register}/{schema}` | The per-schema context document |

Both respond with `Content-Type: application/ld+json`, a body of the
form `{"@context": { … }}`, an `ETag` derived from the underlying
`updated` timestamps (so conditional `If-None-Match` GETs return `304`),
and `Cache-Control` headers. Context documents contain structure only
(terms, IRIs, coercions) — never object data — so they are publicly
dereferenceable for registers with published schemas, letting external
linked-data consumers resolve the contexts they find in
published-object serializations. Unknown register/schema slugs return
`404`.

## Referencing a type another app provides

A schema can point at "whichever installed schema is an Organization"
instead of at one app's schema slug (ADR-048, cross-app semantic
references). Two sides take part.

**The provider advertises what it implements.** A schema implements the
absolute IRIs in `configuration.implements`. Without that list it
implements its `configuration.jsonld.type` and its schema-level
`x-schema-org` marker, when those are absolute IRIs. An explicit
`implements` list replaces those defaults, and only its absolute-IRI
entries count: `["Organization", "https://schema.org/Organization"]`
advertises `https://schema.org/Organization` only.

```json
{
  "slug": "payee",
  "configuration": {
    "jsonld": { "type": "https://schema.org/Organization" }
  }
}
```

**The consumer declares a semantic reference on a property.**

```json
{
  "vendor": {
    "type": "string",
    "referenceSemanticType": "https://schema.org/Organization",
    "referenceSemanticApp": "shillinq"
  }
}
```

The property stores the UUID of the referenced object.
`referenceSemanticApp` is a hint naming the app expected to provide the
type, used to explain why the field is unavailable when it is not
installed. A `referenceSemanticType` that is not an absolute IRI (a bare
`Organization`, an empty string) is refused when the schema is saved,
with a 422 naming the property, because no resolver could ever match it.

**Resolving the reference.**
`GET /api/schemas/resolve-by-implements?uri=https://schema.org/Organization`
answers `{"resolved": true, "register": <id>, "registerSlug": "...",
"schema": <id>, "schemaSlug": "..."}`, or `{"resolved": false}` (HTTP 200)
when no installed schema of an enabled app implements the type. When
several do, the pick is deterministic: a schema in the consuming
register first, then by slug. A form uses
the answer to offer a picker over the provider's objects, and shows the
field disabled when nothing resolves, so the rest of the object stays
editable.

The write direction, handing an object over to whichever schema
implements a kind, is described in
[Semantic object handoff](../features/semantic-object-handoff.md).

## Scope

JSON-LD support is **read-side only**:

- Write endpoints (`POST`/`PUT`/`PATCH`/`DELETE`) do not accept or
  interpret `application/ld+json` request bodies, and are unaffected by
  an `Accept: application/ld+json` header.
- JSON-LD ingest, RDF stores, SPARQL, framing, signed credentials, and
  non-JSON-LD RDF serializations are out of scope.
