## ADDED Requirements

### Requirement: GitHub code search allow-rule

The provider catalogue's `github` entry SHALL permit `GET /search/code` so a
caller can find files by name across every repository the token can see. The
rule SHALL match the path exactly (after the broker strips the query string),
SHALL be GET only, and SHALL NOT grant any other search endpoint or any sub-path
of `/search/code`. The per-file read stays covered by the existing
`GET /repos/*` rule; no additional contents rule is added.

#### Scenario: Code search with a query and a page is allowed

- **WHEN** a brokered call for a `github` credential requests
  `GET /search/code?q=filename%3Apubliccode.yml+path%3A%2F&per_page=100&page=3`
- **THEN** the query string is stripped for rule matching and the
  `GET /search/code` rule matches
- **AND** the outbound URL is
  `https://api.github.com/search/code?q=filename%3Apubliccode.yml+path%3A%2F&per_page=100&page=3`
  with the broker-injected `Authorization` header

#### Scenario: The per-file contents read stays allowed

- **WHEN** a brokered call requests
  `GET /repos/ConductionNL/opencatalogi/contents/publiccode.yml?ref=main`
- **THEN** the existing `GET /repos/*` rule matches and the call proceeds
  host-locked to `api.github.com`

#### Scenario: Writes and other searches stay refused

- **WHEN** a brokered call requests `POST`, `PUT` or `DELETE` on `/search/code`,
  or `GET /search/commits`, `GET /search/users`, `GET /search/code/extra` or
  `GET /search/codes`
- **THEN** the broker refuses it with `no allow-rule matches` and makes no
  outbound call
