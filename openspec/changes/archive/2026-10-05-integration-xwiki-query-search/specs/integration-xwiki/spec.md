# integration-xwiki

## ADDED Requirements

### Requirement: Object-Independent Free-Text Page Search

OpenRegister SHALL expose an object-independent, paginated, free-text search of
the remote xWiki knowledge base at `GET /api/integrations/xwiki/search`, backed
by `XwikiLinkService::searchPages(?string $query, int $limit, int $offset)`. The
xWiki base URL SHALL be resolved exclusively from the OpenConnector `xwiki`
source via `XwikiProvider` + `ExternalIntegrationRouter` — the caller never
supplies an xWiki URL. The endpoint SHALL be `@NoAdminRequired` (any
authenticated user) and read-only.

On success it SHALL return `{ results, total, limit, offset }` where `results`
are normalised page rows (`{ id, title, space, url, breadcrumb, … }`). `limit`
SHALL be clamped to 1..100 (default 25) and `offset` to >= 0 (default 0). The
query MAY be passed as `q` or `search`; an empty query lists available pages.

When the OpenConnector `xwiki` source is missing/unconfigured, or the upstream
xWiki is unreachable, the endpoint SHALL fail closed — returning
`503 { error, details: { cause } }` (cause one of `openconnector-down`,
`openconnector-source-missing`, `provider-auth`, `upstream-service-down`) — and
SHALL never raise a fatal. The service method SHALL return
`{ unavailable, cause, results: [], total: 0, limit, offset }` in that case.

#### Scenario: free-text search returns paginated hits

- **GIVEN** a configured, enabled OpenConnector `xwiki` source pointing at a
  reachable xWiki
- **WHEN** `GET /api/integrations/xwiki/search?q=passport&limit=10` is called by
  an authenticated user
- **THEN** the response is `200 { results, total, limit: 10, offset: 0 }` with
  normalised page rows matching the query
- @e2e exclude Backend API endpoint resolved through OpenConnector; verified by PHPUnit + Newman, not a browser flow.

#### Scenario: unconfigured source fails closed

- **GIVEN** no OpenConnector `xwiki` source exists (or OpenConnector is disabled)
- **WHEN** `GET /api/integrations/xwiki/search?q=anything` is called
- **THEN** the response is `503 { error, details: { cause } }` with a cause of
  `openconnector-down` or `openconnector-source-missing`, and no fatal/500
- @e2e exclude Backend degradation path; verified by PHPUnit + Newman, not a browser flow.

#### Scenario: limit and offset are clamped

- **GIVEN** the search endpoint
- **WHEN** it is called with `limit=9999` and `offset=-5`
- **THEN** the resolved `limit` is 100 and `offset` is 0 in the response envelope
- @e2e exclude Backend input-clamping; verified by PHPUnit, not a browser flow.
