# URN Resource Addressing

## Purpose
Bidirectional URN-URL mapping for system-independent resource identification. This delta documents
the URN resolver HTTP contract AS SHIPPED (`UrnController` → `UrnService`), which is a lean
three-operation surface. It does not claim the broader, still-unimplemented aspirational features
(external mapping table, federation, OIN/RSIN mapping, versioning, export) described elsewhere in
this capability.

## ADDED Requirements

### Requirement: The URN resolver MUST expose resolve, lookup, and bulk operations over a fixed URN grammar
`UrnController` MUST expose three `@NoAdminRequired` / `@NoCSRFRequired` endpoints backed by
`UrnService`: `GET /api/urn/resolve?urn=...` (URN → URL), `GET /api/urn/lookup?url=...`
(URL → URN), and `POST /api/urn/bulk` with body `{ "urns": [...] }` (batch URN → URL). The URN
grammar is `urn:<DEFAULT_NID>:<instance>:<register>:<schema>:<uuid>` where `<DEFAULT_NID>` is the
`UrnService::DEFAULT_NID` constant (`nl-or`).

#### Scenario: Resolve a URN to its canonical URL
- **GIVEN** a syntactically valid URN whose register/schema exist on this instance
- **WHEN** `GET /api/urn/resolve?urn=<urn>` is called
- **THEN** the response MUST return HTTP 200 with `{ urn, url, instance, register, schema, uuid }` where `url` is produced by `UrnService::resolveUrl()` and the segment fields come from `UrnService::parse()`

#### Scenario: Missing urn parameter
- **GIVEN** no `urn` query parameter (null or empty)
- **WHEN** the resolve endpoint is called
- **THEN** the response MUST return HTTP 400 with `{ "error": "urn parameter is required" }`

#### Scenario: Malformed URN
- **GIVEN** a `urn` value that `UrnService::parse()` cannot parse
- **WHEN** the resolve endpoint is called
- **THEN** the response MUST return HTTP 400 with an error describing the expected `urn:<nid>:<instance>:<register>:<schema>:<uuid>` shape

#### Scenario: Parsable URN that does not resolve on this instance
- **GIVEN** a well-formed URN whose register/schema/object is not present on this instance
- **WHEN** the resolve endpoint is called
- **THEN** the response MUST return HTTP 404 with `{ "error": "URN does not resolve on this instance", "urn": ..., "parts": ... }`

#### Scenario: Reverse lookup URL to URN
- **GIVEN** an OpenRegister object URL
- **WHEN** `GET /api/urn/lookup?url=<url>` is called
- **THEN** the response MUST return HTTP 200 with `{ url, urn }` where `urn` is produced by `UrnService::urnFromUrl()`
- **AND** a missing `url` parameter MUST return HTTP 400 with `{ "error": "url parameter is required" }`
- **AND** a URL that is not an OpenRegister object reference MUST return HTTP 404 with `{ "error": "URL is not an OpenRegister object reference", "url": ... }`

#### Scenario: Bulk resolution returns a urn→url map
- **GIVEN** a POST body `{ "urns": ["urn:nl-or:...", ...] }`
- **WHEN** `POST /api/urn/bulk` is called
- **THEN** the response MUST return HTTP 200 with `{ "count": <n>, "resolved": { <urn>: <url-or-null>, ... } }` from `UrnService::resolveBulk()`
- **AND** a missing/empty/non-array `urns` MUST return HTTP 400 with `{ "error": "urns array is required" }`

#### Scenario: Bulk resolution enforces a hard input cap
- **GIVEN** a POST body with more than 1000 URNs
- **WHEN** `POST /api/urn/bulk` is called
- **THEN** the response MUST return HTTP 422 with `{ "error": "Too many URNs (max 1000 per request)", "count": <n> }`
- **AND** this cap MUST NOT be relaxed without an upstream per-user rate limit, because each URN triggers a parse → register-find → schema-find → object-find chain reachable by any authenticated user
