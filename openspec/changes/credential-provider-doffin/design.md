## Context

The credential-broker provider catalogue (`lib/Settings/credential-providers.json`)
is a runtime-immutable security control (credential-broker design D2): its
`allowRules[]` bound exactly which method + path any credential can ever reach on a
host-locked base. New providers ship only through a reviewed release. This change is
such a release step: it adds the `doffin` provider so spectr's Norway ingestion can
consume a broker-held subscription key instead of a key pasted into an OpenConnector
source config.

Source of truth for the provider shape (verified, not assumed):

- **Connector contract** — `spectr/connectors/norway_doffin.json` (read-only, main
  spectr checkout): `source.location` = `https://betaapi.doffin.no/public/v2`,
  auth = header `Ocp-Apim-Subscription-Key: <key>`, and the synchronisation calls
  exactly one endpoint: `GET /notices` with `cpvCodes` / `pageNumber` / `pageSize`
  query parameters. No detail-fetch (`extraDataConfigs`) is configured.
- **Broker mechanics** (worktree HEAD) — `CredentialBrokerService::normalisePath()`
  strips the query string before rule matching and `assertRuleAllowed()` matches
  with exact method + `fnmatch(pathPattern, path)`; `resolveAndLockUrl()` appends
  the caller path to `baseUrl` and enforces the host equality. `injectAuth()` sets
  `header => str_replace('{secret}', $secret, template)` and discards any
  caller-supplied value for that header.

## Decisions

### DF-1 — Base path lives in `baseUrl`, mirroring the `gitlab` precedent

`baseUrl` is `https://betaapi.doffin.no/public/v2` (not the bare host), exactly as
`gitlab` ships `https://gitlab.com/api/v4`. Callers then supply provider-relative
paths (`/notices`), and the allow-rules stay short and readable. The host-lock guard
operates on the parsed host (`betaapi.doffin.no`) regardless of the base path.

*Alternative rejected:* bare-host `baseUrl` with `/public/v2/notices` rules — works,
but breaks the catalogue's established convention and makes every future rule longer.

### DF-2 — GET-only, single allow-rule: `GET /notices`

The connector uses exactly one path. The rule set is therefore the minimum:

```jsonc
"allowRules": [ { "method": "GET", "pathPattern": "/notices" } ]
```

Query parameters (`cpvCodes`, `pageNumber`, `pageSize`) need no pattern coverage —
the broker matches on the query-stripped path. A single-notice detail path
(`/notices/<id>`-style) is deliberately NOT pre-authorised: the connector does not
use one today, and widening later is a cheap reviewed release, whereas shipping an
unused rule is a permanent surface. Doffin's Public API is read-only for our use
case; no non-GET rule may ever be added to this entry without a new review.

### DF-3 — `authScheme` template is the bare `{secret}`

Azure API Management subscription keys are sent as the raw header value —
`Ocp-Apim-Subscription-Key: <key>` — with no `token`/`Bearer` prefix (verified
against the connector's `configuration.headers`). So:

```jsonc
"authScheme": { "header": "Ocp-Apim-Subscription-Key", "template": "{secret}" }
```

`injectAuth()` already owns the header (any caller-supplied
`Ocp-Apim-Subscription-Key` is discarded before injection), so a caller can never
substitute their own key.

## Declarative-vs-imperative note (ADR-031)

Unchanged from credential-broker D2: the catalogue is a deliberate,
security-justified exception to declarative-first-in-the-register — a runtime-
immutable declarative JSON file in `lib/`, never API-writable. This change adds an
entry to that file through the exact mechanism D2 prescribes (code review +
release). No imperative code is added; the change stays `kind: config`.

## Risks / Trade-offs

- [Beta API path unverified live] The connector's own 2026-07-06 probe could not
  disambiguate Azure APIM's 404 (route masking with an invalid key vs. a moved
  beta path) without a real subscription key. The rule follows the connector's
  documented contract (`GET /notices`). If the path has moved, the broker fails
  CLOSED (403 on the unmatched path) — the fix is a follow-up reviewed catalogue
  release, never a runtime widening. The same applies if Doffin promotes
  `betaapi.doffin.no` to a production host: that is a `baseUrl` change in a
  reviewed release.
- [Secret exposure] None added: the subscription key lives behind the existing
  `CredentialStore`; this file carries only the `{secret}` placeholder, never a
  key. Examples in specs/tasks use `YOUR_API_KEY_HERE` placeholders only.
- [Regression to existing providers] The entry is additive; `github`/`gitlab`
  stay byte-identical and the acceptance criteria pin that.

## Migration Plan

1. Add the `doffin` entry + bump the catalogue `version` (apply task).
2. No data migration, no repair step, no schema change. Rollback = revert the file.
3. Operator flow after release: obtain the Doffin subscription key via the
   developer portal (manual), create an OR credential with provider `doffin`
   in Personal settings, and allow the consuming app on it.
