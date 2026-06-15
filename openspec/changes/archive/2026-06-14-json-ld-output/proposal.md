# JSON-LD Output

## Why

OpenRegister's published App Store description promises, in English and
Dutch, "**JSON-LD and Linked Data** — Standards-compliant data that
connects to the broader open data ecosystem", and the README lists
JSON-LD and Schema.org under both Features and "Standards &
Compliance". The feature re-evaluation of 2026-06-11
(`FEATURE-REEVALUATION-2026-06-11/openregister.md`) rated this the
**highest-severity gap**: there are zero hits for
`jsonld` / `json-ld` / `@context` in `lib/` and `src/`, no spec, and no
in-flight change. The promise has no code, spec, or plan behind it —
and the production notice lifts on 12 June 2026.

This change puts a real, scoped capability behind the promise:
**read-side JSON-LD output** for register objects, derived from the
schema definitions OR already has, addressable at the canonical object
URIs OR already mints (`ObjectEntity.uri`, URN addressing).

## What Changes

- **Content negotiation on existing object read endpoints**: `GET
  /api/objects/{register}/{schema}/{id}` (`objects#show`) and `GET
  /api/objects/{register}/{schema}` (`objects#index`) MUST serve
  JSON-LD when the request carries `Accept: application/ld+json`,
  responding with `Content-Type: application/ld+json` and a `Vary:
  Accept` header. No new object routes; absent or `application/json`
  Accept keeps today's response byte-for-byte.
- **`@id` = canonical object URI**: each serialized object carries
  `@id` set to the object's canonical URI (`ObjectEntity.uri`; when
  unset, the absolute `objects#show` route URL), and `@type` resolved
  through the schema's vocabulary mapping (default: the schema term in
  the register context).
- **`@context` derived from schema definitions**: a new
  `JsonLdContextService` generates a JSON-LD `@context` document from
  a schema's `properties`, mapping each property to a vocabulary term.
- **Schema-level vocabulary mapping with sensible defaults**: schema
  (and per-property) metadata may map properties to external
  vocabulary IRIs (e.g. Schema.org: `name → https://schema.org/name`),
  declare a vocabulary base (`@vocab`), and map the schema itself to a
  class IRI (e.g. `https://schema.org/Person`). Unmapped properties
  default to terms under the register's own context document, so every
  schema produces valid JSON-LD with zero configuration.
- **Register `@context` document endpoint**: `GET
  /api/contexts/{register}` returns the register-wide context document
  (one term block per schema) and `GET
  /api/contexts/{register}/{schema}` the per-schema context, both as
  `application/ld+json`. These are the URLs referenced from object
  `@context` values, so consumers can dereference them.
- New capability spec `specs/json-ld-output/spec.md`; OAS generation
  documents the alternate representation.

## Problem

1. **Published promise, zero implementation** — the App Store listing
   (en+nl) and README advertise JSON-LD/Linked Data and Schema.org
   alignment; a government buyer evaluating OR against open-data
   requirements (NL GOV API rules, Common Ground, linked-data
   ecosystems like `standaarden.overheid.nl`) will find nothing.
2. **No canonical linked-data identity** — OR already mints stable
   object URIs and URNs (`urn-resource-addressing`), but API consumers
   get them only as `@self.uri` metadata inside a proprietary
   envelope, not as a dereferenceable `@id` in a standard
   serialization.
3. **Schemas already contain the needed structure** — `Schema.properties`
   is JSON Schema; deriving a `@context` from it is mechanical. The
   gap is purely a missing serialization layer, which makes this the
   cheapest high-severity fix available.

## Proposed Solution

A thin, read-only serialization layer:

- `lib/Service/JsonLd/JsonLdContextService` — builds (and
  request-caches) context documents from `Schema` entities + their
  optional vocabulary mapping configuration.
- `lib/Service/JsonLd/JsonLdSerializer` — wraps a rendered object
  array (the exact array `objects#show` returns today) into JSON-LD:
  injects `@context` (the context-document URL), `@id`, `@type`, and
  lifts `@self` metadata to namespaced terms; collection responses
  become a `@graph` with shared `@context`.
- Content negotiation in `ObjectsController::show()`/`index()` via a
  small `AcceptsJsonLd` helper on the request's `Accept` header —
  no middleware, no impact on other endpoints.
- Vocabulary mapping lives in the schema's existing `configuration`
  JSON under a `jsonld` key (`@vocab`, `type`, `properties` term map),
  round-tripped by the existing schemas API — no migration.

Everything is derived at render time from data OR already has; no new
tables, no background jobs, no breaking changes.

## Out of scope

- **JSON-LD ingest** (parsing `Content-Type: application/ld+json` on
  create/update) — read-side only; write endpoints are untouched.
- RDF stores, SPARQL, Turtle/N-Triples serializations, framing, or
  signed credentials.
- Content negotiation on GraphQL, search, export, or file endpoints
  (export formats have their own spec; a JSON-LD export format can
  follow once this lands).
- A schema-mapping editor UI — mapping is API-level configuration in
  this change; UI is a follow-up once real mappings exist.
- Schema *import from* Schema.org/GGM (separate gap in the
  re-evaluation: `schema-import-standards`).

## See also

- `FEATURE-REEVALUATION-2026-06-11/openregister.md` — the gap analysis
  (MISSING, severity High) and recommendation 1 this change resolves.
- `appinfo/info.xml` (en+nl descriptions) + `README.md` — the
  published promises this change backs with code.
- `openspec/specs/urn-resource-addressing/spec.md` — canonical
  URN/URI identity reused for `@id`.
- `openspec/specs/schema-driven-read-coercion/spec.md` and
  `openapi-generation` — the render pipeline and API documentation
  surfaces this change plugs into.
- JSON-LD 1.1 (W3C Recommendation), Schema.org — target standards.
