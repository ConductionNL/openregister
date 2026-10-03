## Context

The broker's guard 3 (`CredentialBrokerService::assertRuleAllowed`) matches the
upper-cased method and the query-stripped path against each allow-rule with
`fnmatch($pattern, $path)`. Without `FNM_PATHNAME`, `*` also matches `/`.

## Decisions

### D1: one exact rule, no wildcard

`/search/code` is matched exactly. A pattern like `/search/*` would also grant
commit, user and topic search, which no caller has asked for. The exact pattern
refuses `/search/code/anything` and `/search/codes`.

### D2: no new contents rule

The harvest's second call, `GET /repos/{owner}/{repo}/contents/publiccode.yml`,
already matches `GET /repos/*`. Adding a narrower duplicate would grant nothing
and would make the rule count lie about capability.

### D3: rate limits stay the caller's concern

GitHub code search is limited to 30 requests per minute for an authenticated
token. The broker passes the response status and headers (`Link`,
`X-RateLimit-*`, `Retry-After`) back to the caller unchanged, and integriq's
paging node suspends on a rate-limited page. Nothing in the broker changes.
