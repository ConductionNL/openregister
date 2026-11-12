---
kind: code
status: proposed
---

## Why

pipelinq holds a bespoke BRP person-lookup HTTP client —
`OCA\Pipelinq\Service\HaalCentraalClient` — that talks to the RvIG
HaalCentraal Personen v2.0 API. Unlike the KvK / OpenCorporates clients, it
carries two government-grade transport capabilities: OAuth2 client_credentials
token acquisition (cached, bearer-injected) and mutual TLS with a PKIoverheid
client certificate. It also normalises the HAL+JSON person object into a
domain shape and masks BSNs for logging.

The investigation question for this change was: **can OR's canonical transport
(`OCA\OpenConnector\Service\CallService`) actually perform BRP's OAuth2 +
mTLS?** It can:

- **OAuth2 client_credentials** — `AuthenticationService::fetchOAuthTokens`
  (`grant_type=client_credentials`, body or basic_auth client auth) acquires
  the token; the bearer is injected per-call via the `{{ oauthToken(source) }}`
  Twig placeholder rendered into the source's `Authorization` header.
- **mutual TLS (PKIoverheid)** — `CallService::getCertificate` writes the
  source's `configuration.cert` / `configuration.ssl_key` PEM to temp files for
  Guzzle's TLS handshake and cleans them up afterwards.

So BRP is **not** an app-specific transport that OR's generic leaf shouldn't
own — it is exactly the external-leaf shape OR already runs for KvK / xWiki,
just with an OAuth+mTLS source instead of an apikey source. Per ADR-022 the
connection + OAuth credentials + PKIoverheid certificate should live once in
OpenConnector, and OR should expose a thin lookup endpoint pipelinq re-points
to. Today OR has no BRP leaf, so there is nothing to re-point to.

What stays app-specific (and correctly so): BSN elfproef validation, BSN
masking for logs, and the HaalCentraal→domain field normalisation
(`naam` / `geboorte` / `verblijfplaats`, geslacht-code mapping). This leaf
round-trips the raw HAL+JSON person object; the consuming app keeps the
privacy + mapping logic.

## What Changes

- **`BrpPersoonProvider`** (`storage='external'`,
  `getOpenConnectorSource()='brp-haalcentraal'`) — a stateless, read-only
  person-lookup leaf mirroring `KvkProvider`'s OpenConnector wiring. `list()`
  (registry read-path) treats the `_search` filter as a BSN; `lookupByBsn()`
  POSTs a `RaadpleegMetBurgerservicenummer` query to the HaalCentraal
  `/personen` endpoint and returns the raw person objects. Every call routes
  through `ExternalIntegrationRouter` → OpenConnector `CallService`, which
  applies the OAuth2 bearer + mTLS client cert from the source. The provider
  never holds an HTTP client, credentials, or a certificate. `authRequirements()`
  advertises `supports: [oauth2_client_credentials, mtls]`. Degrades
  null-safely to `{ unavailable, cause }` (AD-23) — never a fatal.
- **`PersonLookupController` + route** (read-only, `@NoAdminRequired`):
  `GET /api/integrations/brp/person?bsn=` → `{ results, total }` on success,
  `503 { error, details: { cause } }` when the source is missing/down. The BSN
  travels in the request body to the upstream (never the path → no SSRF, no BSN
  in upstream access logs) and is never logged by OR.
- **Unit tests** — `BrpPersoonProviderTest` (metadata, auth shape, POST body +
  BSN, `personen` / `_embedded.personen` envelope unwrap, the 4-state degraded
  contract, health delegation). The router is mocked; no real BSN is used.

The paired OpenConnector change (`seed-brp-haalcentraal-source`) seeds the
dormant `brp-haalcentraal` source carrying the production HaalCentraal base +
token URLs, the `{{ oauthToken(source) }}` header, and empty OAuth credential +
cert/key placeholders. No secret or certificate is committed in either change.

## Capabilities

### Added Capabilities
- `integration-person-lookup`: a new read-only BRP person-lookup surface
  (lookup by BSN) backed by an external OpenConnector-routed leaf with OAuth2 +
  mTLS configured on the source.

## Impact

- **Code:** `lib/Service/Integration/Providers/BrpPersoonProvider.php`,
  `lib/Controller/PersonLookupController.php`, `appinfo/routes.php` (one route),
  `tests/Unit/Service/Integration/Providers/BrpPersoonProviderTest.php`.
- **Behaviour:** `GET /api/integrations/brp/person?bsn=` resolves the
  `brp-haalcentraal` source and returns the raw HaalCentraal person object, or
  a 503-with-cause when unconfigured/down. With the paired dormant source
  seeded (no credentials/cert), the endpoint degrades to
  `upstream-service-down` rather than `openconnector-source-missing`.
- **Consumers:** pipelinq (future) re-points `HaalCentraalClient` at OR's
  `/api/integrations/brp/person` endpoint, keeping its BSN elfproef +
  masking + field normalisation locally.
- **Secrets / privacy:** none committed. The BSN is never logged by OR and
  travels in the request body only.
