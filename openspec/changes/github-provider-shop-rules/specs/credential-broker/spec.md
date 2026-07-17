## Purpose

Delta against the `credential-broker` capability: the runtime-immutable provider
catalogue (`lib/Settings/credential-providers.json`) widens the existing `github`
provider's allow-rules so OpenBuild's redesigned app shop can search repositories,
resolve the PAT's own login, and create repositories (under the user or an org)
through the broker — with the token held in Doriath custody. The catalogue's own
requirement (runtime-immutable `lib/` file, no mutation API, entries change only via
reviewed release) is unchanged — this delta is one such reviewed release.

## ADDED Requirements

### Requirement: GitHub shop allow-rules

The provider catalogue's `github` entry SHALL permit, in addition to its existing
repo-read, contents-write and git-data-write rules, exactly the following six
allow-rules and no more: `GET /search/repositories` (repository search),
`GET /user` (read the authenticated token's login), `POST /user/repos` (create a
repository under the authenticated user), `POST /orgs/*/repos` (create a
repository under an organisation), `PATCH /repos/*/git/refs/*` (advance an
existing git ref when publishing — the existing `POST /repos/*/git/*` rule covers
ref creation but not the update of an existing branch ref, which the Git Data API
exposes only as `PATCH`), and `PUT /repos/*/topics` (set a repository's discovery
topics when publishing — the existing `PUT /repos/*/contents/*` rule is anchored
under `/contents/` and does not cover the topics endpoint). No rule for the
`github` provider SHALL permit
issues, pull-requests, workflows, webhooks, deletes, or any path outside this set,
and the entry SHALL be mutable only through a reviewed release like every other
catalogue entry. The pre-existing `github` rules and the `gitlab`/`doffin` entries
SHALL remain unchanged.

#### Scenario: Repository search allowed with query parameters

- **WHEN** a brokered call for a `github` credential requests
  `GET /search/repositories?q=topic:nextcloud-app&per_page=25`
- **THEN** the query string is stripped for rule matching and the
  `GET /search/repositories` allow-rule matches, so the call proceeds host-locked to
  `api.github.com`

#### Scenario: Token identity lookup allowed and distinct from user-repos

- **WHEN** a brokered call requests `GET /user`
- **THEN** the `GET /user` allow-rule matches exactly and the call proceeds
- **AND** the already-shipped `GET /user/repos` rule is unaffected (a call to
  `GET /user/repos` still matches its own rule, and `GET /user` does not match
  `/user/repos`)

#### Scenario: Repository creation under the user and an organisation

- **WHEN** a brokered call requests `POST /user/repos`, or `POST /orgs/conduction/repos`
- **THEN** the corresponding new allow-rule (`POST /user/repos` /
  `POST /orgs/*/repos`) matches and the call proceeds host-locked to `api.github.com`
- **AND** a `GET` on `/user/repos` still matches only its own read rule, never the
  create rule (method + path are matched together)

#### Scenario: Existing repo reads and writes remain covered

- **WHEN** a brokered call requests `GET /repos/owner/repo/contents/path`,
  `GET /repos/owner/repo/git/trees/main`, `PUT /repos/owner/repo/contents/file`, or
  `POST /repos/owner/repo/git/blobs`
- **THEN** each is permitted by the pre-existing `github` rules (the `GET /repos/*`
  glob spans path separators), so no additional GET contents/git rule is added

#### Scenario: Branch ref update allowed, repo-settings PATCH denied

- **WHEN** a brokered call requests `PATCH /repos/owner/repo/git/refs/heads/main`
  (fast-forwarding a branch to a new commit during publish)
- **THEN** the `PATCH /repos/*/git/refs/*` allow-rule matches and the call proceeds
  host-locked to `api.github.com`
- **AND** a `PATCH /repos/owner/repo` call (repository settings) matches no rule and
  the broker denies it with the static 403

#### Scenario: Discovery-topic write allowed

- **WHEN** a brokered call requests `PUT /repos/owner/repo/topics` (setting the
  `openbuild-app` discovery topic on a freshly published repository)
- **THEN** the `PUT /repos/*/topics` allow-rule matches and the call proceeds
  host-locked to `api.github.com`

#### Scenario: Out-of-scope GitHub calls stay denied

- **WHEN** a brokered call requests an issues, pull-request, workflow, webhook, or
  delete path (e.g. `POST /repos/owner/repo/issues`, `DELETE /repos/owner/repo`), or
  any other path not in the `github` rule set
- **THEN** no `github` allow-rule matches and the broker denies the call with the
  static 403, failing closed
