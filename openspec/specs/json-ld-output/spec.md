---
status: done
---

# json-ld-output Specification

status: done

## Purpose

Provide read-side JSON-LD (JSON-LD 1.1) serialization of register
objects, backing the App Store / README "JSON-LD and Linked Data"
promise. Objects are retrievable as `application/ld+json` via content
negotiation on the existing object read endpoints, with `@id` set to
the canonical object URI, `@context` derived from schema definitions,
optional schema-level mapping of properties to external vocabulary
terms (e.g. Schema.org), and dereferenceable register/schema `@context`
document endpoints. Scope is read-side output only — JSON-LD ingest is
explicitly excluded.

@e2e exclude Pure read-side serialization / API content-negotiation capability with no UI surface; verified by Newman (tests/integration/openregister-jsonld.postman_collection.json) and PHPUnit (tests/Unit/Service/JsonLd/*, tests/Unit/Controller/ContextsControllerTest.php) per ADR-008 (Playwright is UI-only; API contracts belong in Newman).

## Requirements
### Requirement: Object read endpoints MUST serve JSON-LD via content negotiation

The object read endpoints MUST serve JSON-LD when the client negotiates
for it. Concretely, `GET /api/objects/{register}/{schema}/{id}`
(`objects#show`) and `GET /api/objects/{register}/{schema}`
(`objects#index`) MUST return a
JSON-LD representation when `application/ld+json` is the
highest-weighted matching media type in the request's `Accept` header.
JSON-LD responses MUST carry `Content-Type: application/ld+json` and
`Vary: Accept`. When the `Accept` header is absent, is
`application/json`, or matches only via wildcard, the response MUST
remain the existing plain-JSON representation, unchanged. Access
control (RBAC, multitenancy, published predicate, field-level
security) MUST be identical across both representations — the JSON-LD
serializer wraps the already-rendered object and introduces no second
data path.

#### Scenario: Object retrieved as JSON-LD
- GIVEN an object exists that the user may read
- WHEN the user requests it with `Accept: application/ld+json`
- THEN the response status is 200 with `Content-Type: application/ld+json` and `Vary: Accept`
- AND the body contains `@context`, `@id`, and `@type` alongside the object's data properties

#### Scenario: Default representation is unchanged
- GIVEN the same object
- WHEN it is requested without an `Accept` header or with `Accept: application/json`
- THEN the response is byte-for-byte the existing plain-JSON representation (including the `@self` envelope)

#### Scenario: Access control is representation-independent
- GIVEN an object the user may NOT read under RBAC
- WHEN the user requests it with `Accept: application/ld+json`
- THEN the response is the same authorization error the plain-JSON request produces

#### Scenario: Field-level security applies to JSON-LD output
- GIVEN a property hidden from the requesting user by field-level security
- WHEN the object is served as JSON-LD
- THEN the hidden property is absent from the JSON-LD body exactly as it is from the plain-JSON body

### Requirement: `@id` MUST be the canonical object URI

Each serialized object MUST carry `@id` set to the object's canonical
URI (`ObjectEntity.uri`). When `uri` is unset, `@id` MUST fall back to
the absolute URL of the object's `objects#show` route. The object's
URN (per `urn-resource-addressing`) MUST be exposed as the
`or:urn` term, not as `@id` (the `@id` value must be dereferenceable).

#### Scenario: Canonical URI as @id
- GIVEN an object with `uri = 'https://nc.example.org/index.php/apps/openregister/api/objects/personen/persoon/550e8400'`
- WHEN it is serialized as JSON-LD
- THEN `@id` equals that URI
- AND `or:urn` carries the object's URN

#### Scenario: Fallback @id from the show route
- GIVEN an object whose `uri` field is empty
- WHEN it is serialized as JSON-LD
- THEN `@id` is the absolute `objects#show` URL for its register, schema, and UUID

### Requirement: `@context` MUST be derived from schema definitions with zero-config defaults

The `@context` of a serialized object MUST reference the per-schema
context document URL. The context document MUST be derived from
`Schema.properties`: every schema property gets a term; properties
without an explicit vocabulary mapping default to fragment terms in
the register/schema context document itself, JSON-Schema format hints
map to type coercions (`date` → `xsd:date`, `date-time` →
`xsd:dateTime`, `uri` → `@id`), relation properties are declared
`"@type": "@id"`, and the `or:` prefix
(`https://openregister.app/ns#`) is always defined for OpenRegister
metadata terms. Every schema MUST therefore produce valid JSON-LD
without any configuration. `@self` metadata MUST be lifted to `or:`
terms (`or:register`, `or:schema`, `or:published`, …) and the `@self`
key itself MUST NOT appear in JSON-LD output.

#### Scenario: Zero-config schema yields valid JSON-LD
- GIVEN a schema with properties `name` (string) and `birthDate` (string, format `date`) and no `jsonld` configuration
- WHEN one of its objects is serialized
- THEN `@context` references the schema's context document
- AND that context defines `name` as a fragment term of the context document and `birthDate` with `"@type": "xsd:date"`

#### Scenario: Relation properties serialize as node references
- GIVEN a schema property holding a reference to another register object
- WHEN the context is derived
- THEN the term is declared with `"@type": "@id"` so the related object's URI/UUID reads as a node reference

#### Scenario: @self is lifted, not emitted
- GIVEN any object serialized as JSON-LD
- THEN the body contains no `@self` key
- AND `or:register`, `or:schema`, and `or:urn` carry the corresponding metadata

### Requirement: Schemas MUST support property-to-vocabulary mapping with sensible defaults

Schemas MUST support an optional property-to-vocabulary mapping that
the derived context honours. A schema MAY declare a `jsonld` block in its existing `configuration`
JSON: `@vocab` (vocabulary base IRI), `type` (class IRI used as the
object `@type`), and `properties` (map of property name → term IRI,
e.g. Schema.org). Mapped properties MUST use the declared IRIs in the
derived context; unmapped properties keep the zero-config defaults;
without a `type` mapping, `@type` defaults to the schema's term. The
mapping MUST be validated on schema save (absolute IRIs or terms
resolvable against `@vocab`; invalid mappings rejected with 400).

#### Scenario: Schema.org mapping applied
- GIVEN schema `persoon` configured with `jsonld: { "@vocab": "https://schema.org/", "type": "https://schema.org/Person", "properties": { "name": "https://schema.org/name" } }`
- WHEN one of its objects is serialized
- THEN `@type` resolves to `https://schema.org/Person`
- AND the derived context maps `name` to `https://schema.org/name`

#### Scenario: Partial mapping mixes vocabularies
- GIVEN the same schema also has an unmapped property `dossiernummer`
- WHEN the context is derived
- THEN `name` uses the Schema.org IRI while `dossiernummer` keeps its zero-config register-context term

#### Scenario: Invalid mapping is rejected on save
- GIVEN a schema update whose `jsonld.properties` maps `name` to the non-IRI value `'just a label'`
- WHEN the schema is saved via the schemas API
- THEN the request fails with 400 and a structured validation error
- AND the stored schema configuration is unchanged

### Requirement: Registers MUST expose dereferenceable `@context` document endpoints

`GET /api/contexts/{register}` MUST return the register-wide context
document and `GET /api/contexts/{register}/{schema}` the per-schema
context document, both with `Content-Type: application/ld+json`, an
`ETag` derived from the underlying schema/register `updated`
timestamps, and cache headers. These URLs are the values object
serializations reference in `@context`. Context documents contain
structure only (terms, IRIs, coercions) — never object data. They MUST
be readable by authenticated tenant users, and MUST be publicly
dereferenceable for registers containing published schemas so external
consumers can resolve contexts found in published-object
serializations. Unknown register/schema slugs MUST return 404.

#### Scenario: Object @context dereferences to the schema context document
- GIVEN an object serialized as JSON-LD with `@context` = the schema context URL
- WHEN a client performs a GET on that URL
- THEN it receives 200 with `Content-Type: application/ld+json` and a body of the form `{"@context": { … }}` containing the schema's terms

#### Scenario: Conditional GET on a context document
- GIVEN a client previously fetched a schema context and stored its `ETag`
- WHEN it repeats the request with `If-None-Match` and the schema has not changed
- THEN the response is 304 with no body

#### Scenario: Anonymous dereference for published data
- GIVEN a register containing a published schema
- WHEN an unauthenticated client GETs that schema's context document
- THEN it receives the context document (200)

#### Scenario: Unknown slug
- GIVEN no register with slug `does-not-exist`
- WHEN a client GETs `/api/contexts/does-not-exist`
- THEN the response is 404

### Requirement: Collection responses MUST serialize as a `@graph` with shared context

When `objects#index` is served as JSON-LD, the response MUST be a
single document with one top-level `@context` and the page's objects
as nodes in `@graph`, each carrying its own `@id` and `@type` but no
repeated `@context`. Pagination metadata MUST be expressed with `or:`
terms (`or:total`, `or:page`, `or:next`) so the document remains valid
JSON-LD.

#### Scenario: Object list as a graph
- GIVEN a schema with three readable objects
- WHEN the collection is requested with `Accept: application/ld+json`
- THEN the body has one top-level `@context` and a `@graph` array of three nodes, each with `@id` and `@type`
- AND `or:total` equals 3

#### Scenario: Paginated graph links to the next page
- GIVEN more objects than the page limit
- WHEN the first page is requested as JSON-LD
- THEN `or:next` carries the URL of the next page

### Requirement: JSON-LD support MUST be read-side only

Write endpoints (POST/PUT/PATCH/DELETE on objects) MUST NOT accept or
interpret `application/ld+json` request bodies in this capability;
their behaviour MUST be unaffected by clients sending
`Accept: application/ld+json`. JSON-LD ingest, RDF stores, SPARQL, and
non-JSON-LD RDF serializations are out of scope.

#### Scenario: Write verbs are unaffected by JSON-LD Accept headers
- GIVEN a valid plain-JSON object create request
- WHEN it is sent with `Accept: application/ld+json`
- THEN the create behaves exactly as without that header and responds with the existing plain-JSON representation

#### Scenario: JSON-LD request bodies are not interpreted
- GIVEN a PUT request with `Content-Type: application/ld+json`
- WHEN it reaches the objects API
- THEN the request is handled exactly as an unsupported/plain body is handled today — no JSON-LD expansion or context processing occurs

