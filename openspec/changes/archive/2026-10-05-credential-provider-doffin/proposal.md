---
kind: config
depends_on:
  - credential-broker
---

## Why

Spectr's Norway/Doffin ingestion (the W3-revival OpenConnector config at
`spectr/connectors/norway_doffin.json`) is blocked on a real Doffin Public API
subscription key. Today that connector ships as a documented draft with a
placeholder key pasted directly into the OpenConnector source configuration —
exactly the "paste your long-lived secret into the consuming app" anti-pattern
the credential broker exists to remove. The broker's provider catalogue
(`lib/Settings/credential-providers.json`, runtime-immutable per
credential-broker design D2) currently ships only `github` and `gitlab`, so no
credential can legally target Doffin: the broker fails closed on an unknown
provider. Adding providers requires a reviewed release — this change IS that
reviewed release step for `doffin`.

## What Changes

- Add a `doffin` entry to the runtime-immutable provider catalogue
  `lib/Settings/credential-providers.json`, mirroring the exact shape of the
  existing `github`/`gitlab` entries:
  - `baseUrl`: `https://betaapi.doffin.no/public/v2` — host-locked; verified
    against the spectr connector's `source.location` (Doffin's Azure API
    Management-hosted Public API, still on its beta host).
  - `authScheme`: header `Ocp-Apim-Subscription-Key`, template `{secret}` —
    the APIM subscription-key header carries the bare key, no prefix
    (verified against the connector's `configuration.headers`).
  - `allowRules`: **GET-only**, limited to the single notice-search path the
    connector actually uses: `GET /notices` (the connector's
    `sourceConfig.endpoint` relative to the base; its `cpvCodes` /
    `pageNumber` / `pageSize` query parameters are stripped by the broker's
    path normalisation before rule matching, so the exact pattern `/notices`
    covers every search call).
- Bump the catalogue's top-level `version`.
- No code, no endpoints, no schema changes — the broker, catalogue loader, and
  guard chain already handle any catalogue entry generically.

## Non-Goals

- **Belgium OAuth2 provider — PARKED** per program decision (round 2,
  2026-07-06). The catalogue's `authScheme` is header-injection only; an
  OAuth2 token-refresh flow is a broker feature, not a catalogue entry, and is
  explicitly out of scope here.
- **GitHub-for-spectr — PARKED** per the same program decision. The existing
  `github` entry is untouched; no spectr-specific GitHub rules are added.
- No widening of the existing `github`/`gitlab` entries.
- No runtime/API mutation path for the catalogue — the file stays
  runtime-immutable (credential-broker D2); this change is itself the
  reviewed-release mechanism that D2 prescribes.
- Obtaining the real Doffin subscription key (developer-portal signup) stays a
  manual operator step ("Ruben territory"); the key is pasted once into the OR
  credential settings UI, never into this file or any connector JSON.

## Capabilities

### Modified Capabilities

- `credential-broker`: the provider catalogue gains a third entry, `doffin` —
  host-locked to Doffin's Public API base, subscription-key header injection,
  GET-only allow-rules scoped to the notice-search path. The catalogue
  requirement itself (runtime-immutable `lib/` file, no mutation API) is
  unchanged; this is an additive entry shipped through the designed
  reviewed-release path.

## Impact

- **Changed file**: `lib/Settings/credential-providers.json` (additive entry +
  version bump). `github` and `gitlab` entries stay byte-identical.
- **No PHP/Vue changes**: `ProviderCatalogue`, `CredentialBrokerService` (four
  ordered guards incl. `fnmatch` rule matching and host-lock), and
  `CredentialController::providers()` consume catalogue entries generically.
- **Downstream**: once merged, a user stores the Doffin subscription key ONCE
  as an OR credential (provider `doffin`), and the spectr ingestion path can
  call `GET /notices` through the broker without any app holding the key.
- **Known caveat carried from the connector**: the connector's own live check
  (2026-07-06) could not confirm the `/notices` path against a real key
  (Azure APIM returned 404 for keyless/fake-key probes — route masking or a
  moved beta path). The allow-rule follows the connector's documented
  contract; if the path moved, correcting it is another reviewed catalogue
  release — the failure mode is fail-closed (403), never a widened surface.
