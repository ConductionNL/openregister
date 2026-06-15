# integration-leaf-foundation Specification

## Purpose
TBD - created by archiving change integration-leaf-foundation-shares-analytics. Update Purpose after archive.
## Requirements
### Requirement: Mint a public case-token link

The system SHALL let an authenticated user mint an opaque, high-entropy public
token bound to an OpenRegister object (register / schema / uuid), with an
optional label and time-to-live, and return an absolute public resolve URL. The
token MUST be persisted with the minter's uid and creation time. Minting MUST be
exposed through `SharesProvider::create()` for the `public-token` payload type;
the existing NC file-share `list()` / `delete()` behaviour MUST be unchanged.

#### Scenario: Mint a token for an object
- **GIVEN** an authenticated user and object `obj-1` in register 3 / schema 9
- **WHEN** `SharesProvider::create('3','9','obj-1',{type:'public-token',label:'Track your case',ttlSeconds:7200})` is called
- **THEN** a token row MUST be persisted bound to `obj-1` with the minter's uid
- **AND** the result MUST contain the token and an absolute public URL
- **AND** an `expiresAt` 7200 seconds in the future MUST be set
- `@e2e exclude` Backend foundation surface consumed by the procest leaf; covered by `CaseTokenServiceTest` + `SharesProviderTest`. No OR-side UI flow.

#### Scenario: Anonymous user cannot mint
- **GIVEN** no logged-in user
- **WHEN** `CaseTokenService::mint('obj-1')` is called
- **THEN** the system MUST throw an `InvalidArgumentException` (fail-closed write)
- `@e2e exclude` Backend fail-closed guard; covered by `CaseTokenServiceTest`.

### Requirement: Resolve a public case token RBAC-respecting

The system SHALL expose a `#[PublicPage]` endpoint `GET /api/public/case-tokens/{token}`
that resolves a valid token to a PUBLIC-SAFE view of the referenced object. The
resolve MUST run the canonical OpenRegister read path with `_rbac: true` so that
only fields the public group may read are returned; it MUST NOT bypass RBAC. Any
unknown, revoked, expired, or RBAC-denied resolution MUST return HTTP 404 with a
uniform body so the endpoint is not an enumeration oracle.

#### Scenario: Resolve a valid token returns the public-safe view
- **GIVEN** a valid (not revoked, not expired) token for a publicly-readable object
- **WHEN** `GET /api/public/case-tokens/{token}` is requested anonymously
- **THEN** the response MUST be HTTP 200 with the RBAC-rendered object view
- **AND** the read MUST have run with `_rbac: true`
- `@e2e exclude` Anonymous public endpoint backed by the RBAC read path; covered by `CaseTokenServiceTest` RBAC assertions. End-to-end anon-resolve belongs to the procest `public-share` leaf.

#### Scenario: Revoked / expired / unknown token returns 404
- **GIVEN** a token that is unknown, revoked, or expired
- **WHEN** the resolve endpoint is requested
- **THEN** the system MUST return HTTP 404 with a uniform "Not Found" body (no oracle)
- `@e2e exclude` Fail-closed guard; covered by `CaseTokenServiceTest`.

### Requirement: Revoke a public case token

The system SHALL let a token be revoked (idempotently) by its opaque string or
numeric id, after which the resolve endpoint MUST fail closed. Revocation MUST be
reachable through `SharesProvider::delete()` via a `token:`-prefixed entity id
without affecting NC file-share deletion.

#### Scenario: Revoke invalidates a token
- **GIVEN** a valid token
- **WHEN** `CaseTokenService::revoke()` is called for it
- **THEN** the token's `revokedAt` MUST be set
- **AND** a subsequent resolve MUST return 404
- `@e2e exclude` Backend lifecycle; covered by `CaseTokenServiceTest` + `SharesProviderTest`.

### Requirement: Register a page-level analytics series

The system SHALL let an authenticated user register (upsert by stable key) a
page-level chart series consisting of labels and datasets, scoped by an optional
register / schema and a visibility tier (`private` / `group` / `public`), and a
chart-type hint. Registering MUST also declare a matching chart page-widget on
the `IntegrationRegistry` so the render layer can discover it. The series maths
is owned by the leaf; OpenRegister owns persistence + the render contract.

#### Scenario: Register a series and declare its widget
- **GIVEN** an authenticated user
- **WHEN** `AnalyticsSeriesService::register('sla-breaches', labels, datasets, ...)` is called
- **THEN** the series MUST be persisted under `sla-breaches`
- **AND** a page widget `analytics-series:sla-breaches` of type `chart` MUST be registered
- `@e2e exclude` Backend render-surface contract consumed by the procest SLA-dashboard leaf; covered by `AnalyticsSeriesServiceTest` + `IntegrationRegistryTest`.

#### Scenario: Invalid visibility or chart type is rejected
- **GIVEN** an authenticated user
- **WHEN** a series is registered with an unknown visibility or chart type
- **THEN** the system MUST throw an `InvalidArgumentException`
- `@e2e exclude` Backend validation; covered by `AnalyticsSeriesServiceTest`.

### Requirement: Fetch a page-level analytics series RBAC-scoped

The system SHALL expose `GET /api/integrations/analytics/series/{seriesKey}` to
fetch a registered series, enforcing visibility: a `public` series is readable by
anyone, while `group` / `private` series are readable only by the creator or an
admin. A disallowed or unknown fetch MUST return HTTP 404 (no enumeration oracle).

#### Scenario: Public series readable by anyone
- **GIVEN** a series registered with visibility `public`
- **WHEN** any caller fetches it
- **THEN** the chart-ready render contract MUST be returned
- `@e2e exclude` Backend RBAC read; covered by `AnalyticsSeriesServiceTest`.

#### Scenario: Private series denied to a non-creator
- **GIVEN** a `private` series created by another user
- **WHEN** a non-creator non-admin fetches it
- **THEN** the system MUST return null / HTTP 404 (fail-closed, no oracle)
- `@e2e exclude` Backend RBAC guard; covered by `AnalyticsSeriesServiceTest`.

