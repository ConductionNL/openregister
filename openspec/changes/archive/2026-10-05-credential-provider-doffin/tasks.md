## 1. Catalogue entry

- [x] 1.1 Add the `doffin` entry to `lib/Settings/credential-providers.json`: `identifier` `doffin`, `title` `Doffin (Norway)`, `baseUrl` `https://betaapi.doffin.no/public/v2`, `authScheme` `{header: "Ocp-Apim-Subscription-Key", template: "{secret}"}`, `allowRules` `[{method: "GET", pathPattern: "/notices"}]` — GET-only, nothing else. Leave `github`/`gitlab` byte-identical.
- [x] 1.2 Bump the catalogue's top-level `version` and extend the file's `$comment` only if the doffin caveat (beta host, path carried from the spectr connector contract) needs recording there.

## 2. Verification

- [x] 2.1 JSON-validate the catalogue file; run `composer check:strict`.
- [x] 2.2 Add/extend unit coverage: `ProviderCatalogue::get('doffin')` returns the entry; broker rule matching permits `GET /notices` (with query string) and denies `POST /notices` and `GET` on any other path; host-lock resolves to `betaapi.doffin.no`.
- [x] 2.3 Confirm no new mutation surface: no route, controller method, or service writes the catalogue (unchanged invariant; grep-level check is enough).

## Acceptance criteria

- The `doffin` catalogue entry exists with host-locked `baseUrl` `https://betaapi.doffin.no/public/v2`, header `Ocp-Apim-Subscription-Key`, template exactly `{secret}`, and a GET-only rule set limited to `/notices`.
- `github` and `gitlab` entries are unchanged (byte-identical).
- No secret, key, or realistic-looking token appears anywhere in the diff — placeholders only (`YOUR_API_KEY_HERE` in tests/spec examples).
- The catalogue remains runtime-immutable: no API can create, update, or delete the `doffin` entry or its rules.
- Broker behaviour for unknown/unlisted doffin paths is fail-closed (static 403).

## Quality checklist

- ADR-031: catalogue stays the reviewed-release, runtime-immutable exception (credential-broker D2); this change is additive config only.
- Belgium OAuth2 and GitHub-for-spectr remain PARKED — nothing in this change touches or anticipates them.
- Kind stays `config`: no PHP/Vue surface ships beyond test coverage of existing generic code paths.
