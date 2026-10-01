## 1. Catalogue

- [x] 1.1 Add `{ "method": "GET", "pathPattern": "/search/code" }` with a `$why` to the `github` entry's `allowRules` in `lib/Settings/credential-providers.json`. Leave every other rule byte-identical.
- [x] 1.2 Bump the catalogue `version` to 1.11.0.

## 2. Verification

- [x] 2.1 `tests/Unit/Service/Credential/GithubCodeSearchRuleTest.php`: drives `CredentialBrokerService::request()` with the shipped catalogue. `GET /search/code` with and without a query string, and `GET /repos/{owner}/{repo}/contents/publiccode.yml`, reach the client host-locked to `api.github.com` with the token header. POST/PUT/DELETE on `/search/code`, `GET /search/commits`, `GET /search/users`, `GET /search/code/extra` and `GET /search/codes` are refused with no outbound call. Fails without the rule (checked by removing it: 3 of 11 red).
- [x] 2.2 Raise the rule-count pin in `DoffinProviderTest` from 18 to 19.
- [x] 2.3 Live on the :8096 stack: a `github` credential, brokered `GET /search/code` reaches the outbound call (no allow-rule refusal), `POST /search/code` and `GET /search/commits` are refused.
- [ ] 2.4 Authenticated harvest against real GitHub with Ruben's token (coordinator; no token exists yet).
