# Design: JSON-LD Output

## Reuse analysis

- `ObjectEntity::jsonSerialize()` — already returns the rendered
  object with `@self` metadata (`id`, `uri`, `register`, `schema`,
  `published`, …). The serializer wraps this array; it does NOT
  re-render objects, so RBAC, field-level security, schema-driven read
  coercion, and the published predicate all keep applying.
- `ObjectEntity.uri` + URN addressing (`urn-resource-addressing`) —
  canonical identity reused as `@id` (URI preferred; URN exposed as a
  second alias term, not as `@id`, since `@id` should dereference).
- `Schema.properties` (JSON Schema) + `Schema.configuration` (free
  JSON column) — context derivation source + storage for the optional
  `jsonld` mapping block. No migration; the schemas API already
  round-trips `configuration`.
- `IURLGenerator::linkToRouteAbsolute()` — context-document URLs and
  the `@id` fallback.
- `OasService` / `openapi-generation` — documents the
  `application/ld+json` content type on the read endpoints.

## Serialization shape

`GET /api/objects/{register}/{schema}/{id}` with
`Accept: application/ld+json`:

```jsonc
{
  "@context": "https://nc.example.org/index.php/apps/openregister/api/contexts/personen/persoon",
  "@id": "https://nc.example.org/index.php/apps/openregister/api/objects/personen/persoon/550e8400-…",
  "@type": "persoon",                  // or mapped class IRI, e.g. "schema:Person"
  "name": "Jansen",
  "birthDate": "1980-04-01",
  "or:register": "personen",           // @self metadata under the or: prefix
  "or:schema": "persoon",
  "or:urn": "urn:gemeente-a:openregister:personen:persoon:550e8400-…",
  "or:published": "2026-05-01T00:00:00+00:00"
}
```

Collection (`objects#index`) responses: top-level `@context` +
`@graph: [ …objects without repeated @context… ]`, with pagination
metadata kept under `or:` terms (`or:total`, `or:page`, `or:next`)
so the document stays valid JSON-LD without inventing a Hydra
dependency.

## Context derivation

`JsonLdContextService::buildSchemaContext(Schema $schema): array`

1. Start from the schema's `configuration.jsonld` block (all keys
   optional):

   ```jsonc
   {
     "jsonld": {
       "@vocab": "https://schema.org/",          // vocabulary base
       "type": "https://schema.org/Person",       // class IRI for @type
       "properties": {                             // per-property term map
         "name": "https://schema.org/name",
         "birthDate": "https://schema.org/birthDate"
       }
     }
   }
   ```

2. Defaults (zero-config path): no `@vocab` → terms resolve against
   the register context document itself
   (`{contextUrl}#{property}`), `@type` → the schema slug as a term in
   that context. Every schema therefore yields valid, dereferenceable
   JSON-LD out of the box.
3. JSON-Schema `format`/`type` hints map to `@type` coercions in the
   context (`date`/`date-time` → `xsd:date`/`xsd:dateTime`,
   `uri` → `@id`); object-relation properties (`$ref`-style) are
   declared `"@type": "@id"` so related-object UUIDs/URIs read as
   node references.
4. The `or:` prefix (`https://openregister.app/ns#`) is always present
   for the `@self`-derived metadata terms.
5. Contexts are deterministic per (schema, version) and request-cached;
   the HTTP endpoints send `ETag` based on schema `updated`.

## Content negotiation

- Helper `JsonLdSerializer::wantsJsonLd(IRequest $request): bool` —
  true when `application/ld+json` is the highest-q matching media type
  in `Accept`. Wildcards (`*/*`, `application/*`) and absent headers
  resolve to plain JSON (default unchanged).
- Applied **only** in `ObjectsController::show()` and `::index()`
  after the existing render path; the rendered array is passed through
  `JsonLdSerializer::serialize()` / `::serializeCollection()` and
  returned with `Content-Type: application/ld+json` + `Vary: Accept`.
- Write verbs (POST/PUT/PATCH/DELETE) are untouched; sending
  `Accept: application/ld+json` on them affects only the response
  representation of the (unchanged) JSON body and is explicitly out of
  scope to implement in this change — they keep returning JSON.

## Context endpoints

```php
['name' => 'contexts#register', 'url' => '/api/contexts/{register}',          'verb' => 'GET'],
['name' => 'contexts#schema',   'url' => '/api/contexts/{register}/{schema}', 'verb' => 'GET'],
```

- `ContextsController` (new, read-only). Auth posture: same visibility
  as the schema metadata itself — readable for authenticated users of
  the tenant; additionally `#[PublicPage]`-served when the register
  has published schemas, because external linked-data consumers must
  be able to dereference the `@context` URL found in published-object
  serializations (context documents contain structure, never object
  data).
- Responses are `application/ld+json` documents of the form
  `{"@context": { … }}` with `ETag` + `Cache-Control` headers.

## Risks / notes

- **Property-name collisions with JSON-LD keywords**: object data keys
  starting with `@` (other than the injected `@context`/`@id`/`@type`)
  are emitted under the `or:raw#` escape prefix; `@self` itself is
  replaced by the lifted `or:` terms, not emitted.
- **Performance**: serialization is an O(properties) array transform on
  an already-rendered object; context building hits `SchemaMapper`
  once per request per schema (request cache).
- **Honesty of the Schema.org claim**: defaults produce OR-local
  vocabulary terms; Schema.org alignment is opt-in per schema via the
  mapping block. README wording should say exactly that (task 6.2).
