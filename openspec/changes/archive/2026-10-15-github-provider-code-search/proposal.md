---
kind: config
depends_on:
  - credential-broker
---

## Why

OpenCatalogi's publiccode harvest finds `publiccode.yml` files across all of
GitHub, not inside one organisation. integriq ships the `github-api` source for
it, authenticated through the broker proxy with a credential named
`github-publiccode` (provider `github`, ADR-064). The first call of every run is
GitHub code search, `GET /search/code?q=filename:publiccode.yml+path:/`, and the
`github` provider's allow-rules do not include it, so the broker refuses the
harvest before it starts (`no allow-rule matches GET /search/code`).

The per-file read (`GET /repos/{owner}/{repo}/contents/{path}`) is already
covered by the existing `GET /repos/*` rule: the broker matches with `fnmatch`
without `FNM_PATHNAME`, so `*` spans slashes.

## What Changes

- Add one allow-rule to the `github` entry of
  `lib/Settings/credential-providers.json`:
  `{ "method": "GET", "pathPattern": "/search/code" }`, with a `$why`.
- Bump the catalogue `version` to 1.11.0.
- No code change: the broker strips the query string before matching, so the
  exact pattern covers every search page.

## Impact

- Read-only and host-locked to `api.github.com`. A code search hit carries a
  path, a repository name and a blob sha, never file content, so it returns less
  than `GET /repos/*` already does.
- No write method, no other search endpoint (`/search/commits`, `/search/users`)
  and no sub-path of `/search/code` becomes reachable.
