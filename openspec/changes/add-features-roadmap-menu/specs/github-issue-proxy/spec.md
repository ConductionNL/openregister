---
status: proposed
capability: github-issue-proxy
---

# GitHub Issue Proxy

## Purpose

Expose a thin, cached, server-side proxy over GitHub's issues API so every Conduction
Nextcloud app can surface its own roadmap (open issues, sorted by reactions) AND let
authenticated users file new feature requests that land directly as GitHub issues on the
app's repo. The proxy reuses OpenRegister's existing `GitHubHandler` utility and both
existing PAT stores: the per-user `openregister::github_token` (IConfig) preferred for
authorship, and the app-level `openregister::github_api_token` (IAppConfig) used as a
fallback for reads and for submissions when the user has no personal PAT.

## ADDED Requirements

### Requirement: Issues list endpoint

OpenRegister SHALL expose `GET /api/github/issues` that accepts the query parameters `repo`
(required, format `<owner>/<repo>`), `state` (optional, default `open`), `sort` (optional,
default `reactions-+1`), and `per_page` (optional integer, default 30, maximum 100). The
endpoint SHALL return a JSON response containing an `items` array of issue objects fetched
from GitHub for the given repository, with sensitive data stripped. The endpoint MUST carry
`#[NoCSRFRequired]` since it is a pure read with no side effects.

#### Scenario: Successful fetch of open issues

- **WHEN** an authenticated Nextcloud user requests `GET /api/github/issues?repo=ConductionNL/openregister`
- **AND** the app-level GitHub PAT is configured
- **THEN** the endpoint SHALL return HTTP 200 with `{"items": [...], "total": N}`
- **AND** each item SHALL contain `number`, `title`, `body`, `html_url`, `user.login`,
  `user.avatar_url`, `reactions.total_count`, `reactions.+1`, `created_at`, `updated_at`, and
  `labels[].{name,color}`
- **AND** items SHALL be sorted by `reactions-+1` descending

#### Scenario: Missing repo parameter

- **WHEN** a user requests `GET /api/github/issues` with no `repo` query parameter
- **THEN** the endpoint SHALL return HTTP 400 with a structured error code `repo_required`

### Requirement: Issue creation endpoint

OpenRegister SHALL expose `POST /api/github/issues` that accepts a JSON body of the shape
`{repo: string, title: string, body: string, specRef?: string}` and creates a GitHub issue
on the named repository. The endpoint MUST enforce CSRF protection (no `#[NoCSRFRequired]`
attribute). The endpoint SHALL validate that `repo` matches `^[\w.-]+/[\w.-]+$`, that
`title` is 3-200 characters, and that `body` is at least 10 characters; violations SHALL
yield HTTP 400 with a structured error code and SHALL NOT issue an outbound GitHub request.

#### Scenario: Successful submission

- **WHEN** an authenticated user POSTs `{repo: "ConductionNL/openregister", title: "Add dark mode", body: "A longer description of the feature idea."}` with a valid CSRF token
- **AND** either the user or server GitHub PAT is configured
- **AND** the user is not currently rate-limited
- **THEN** the endpoint SHALL issue an authenticated POST to GitHub's
  `/repos/{owner}/{repo}/issues` endpoint
- **AND** it SHALL return HTTP 201 with `{number, html_url, state}`

#### Scenario: Missing CSRF token

- **WHEN** an authenticated user POSTs to `/api/github/issues` without a CSRF token
- **THEN** Nextcloud's middleware SHALL return HTTP 412 (or the configured CSRF error
  status) before the controller runs

#### Scenario: Title too short

- **WHEN** a user POSTs `{repo: "ConductionNL/openregister", title: "Hi", body: "A valid body at least ten chars."}`
- **THEN** the endpoint SHALL return HTTP 400 with error code `title_invalid_length`
- **AND** no outbound GitHub request SHALL be issued

#### Scenario: Body too short

- **WHEN** a user POSTs `{repo: "ConductionNL/openregister", title: "A valid title", body: "short"}`
- **THEN** the endpoint SHALL return HTTP 400 with error code `body_invalid_length`
- **AND** no outbound GitHub request SHALL be issued

### Requirement: Authorship fallback

`GitHubHandler::createIssue` SHALL prefer the authenticated user's stored per-user token
read from `IConfig` key `openregister::github_token` when present. When that value is empty
or absent, it SHALL fall back to the app-level PAT read from `IAppConfig` key
`openregister::github_api_token`. When falling back, the handler SHALL prefix the body sent
to GitHub with the attribution block:

```
> Submitted by **<display_name>** via <instance_url>

---

```

where `<display_name>` is the Nextcloud user's display name and `<instance_url>` is the
Nextcloud base URL. When the user-level PAT is used, no attribution prefix SHALL be added
and the issue SHALL be authored as the user's own GitHub identity.

#### Scenario: User PAT present

- **WHEN** the submitting user has `openregister::github_token` set to a valid token
- **THEN** the outbound POST SHALL carry that token
- **AND** the issue body sent to GitHub SHALL be the user's body verbatim, with no
  attribution prefix

#### Scenario: User PAT absent, server PAT present

- **WHEN** the submitting user has no per-user token
- **AND** the app-level `openregister::github_api_token` is set
- **THEN** the outbound POST SHALL carry the app-level token
- **AND** the issue body sent to GitHub SHALL begin with
  `> Submitted by **<display_name>** via <instance_url>\n\n---\n\n` followed by the user's
  body verbatim

#### Scenario: Both PATs absent

- **WHEN** neither the user PAT nor the app-level PAT is configured
- **THEN** the endpoint SHALL return HTTP 503 with error code
  `github_pat_not_configured`
- **AND** no outbound GitHub request SHALL be issued

### Requirement: specRef handling on submission

When the request body includes a non-empty `specRef` value, the controller SHALL append the
block `\n\n---\nRelated capability: `<specRef>`\n` to the issue body sent to GitHub AND
SHALL apply a `specRef:<slug>` label to the created issue. The label SHALL be added even if
the label does not yet exist on the target repository (GitHub creates it on-demand).

#### Scenario: specRef provided

- **WHEN** a user submits `{repo: "ConductionNL/openregister", title: "A title", body: "A valid body.", specRef: "catalog-management"}`
- **THEN** the body sent to GitHub SHALL end with
  `\n\n---\nRelated capability: `catalog-management`\n`
- **AND** the created issue SHALL carry a label named `specRef:catalog-management`

#### Scenario: specRef omitted

- **WHEN** a user submits a request with no `specRef` field
- **THEN** the body sent to GitHub SHALL equal the user's body (with any applicable
  attribution prefix) and no `---\nRelated capability:` suffix
- **AND** no `specRef:*` label SHALL be applied

### Requirement: Submission rate limit

The `POST /api/github/issues` endpoint SHALL enforce a per-user rate limit of one
submission per 60 seconds, stored in APCu under the key
`openregister.feature_submission:<user_id>`. When a user exceeds the limit, the endpoint
SHALL return HTTP 429 with body `{"error": "rate_limited", "retry_after": <seconds>}` where
`retry_after` is the remaining APCu TTL in whole seconds. No outbound GitHub request SHALL
be issued while the user is rate-limited.

#### Scenario: Second submission within 60 seconds

- **WHEN** a user submits successfully at T=0
- **AND** the same user submits again at T=10s
- **THEN** the second response SHALL be HTTP 429
- **AND** the body SHALL contain `error: "rate_limited"` and a positive integer
  `retry_after` not greater than 60

### Requirement: Authentication required for submission

The `POST /api/github/issues` endpoint SHALL reject unauthenticated callers with HTTP 401.
The endpoint MUST NOT carry `#[PublicPage]`; it MUST carry `#[NoAdminRequired]` so that any
logged-in user can submit.

#### Scenario: Unauthenticated submission

- **WHEN** an anonymous request POSTs `/api/github/issues`
- **THEN** Nextcloud's middleware SHALL return HTTP 401 before the controller runs

### Requirement: Successful submission response

On success, the controller SHALL return HTTP 201 with a JSON body containing
`{"number": <int>, "html_url": <string>, "state": <string>}` taken from GitHub's response.
When the original request included a `specRef`, the response body SHALL additionally
include `"specRef": <value>`.

#### Scenario: Success payload shape

- **WHEN** a submission succeeds and GitHub returns issue `{number: 42, html_url: "https://github.com/.../issues/42", state: "open"}`
- **AND** the original request carried `specRef: "catalog-management"`
- **THEN** the controller SHALL respond HTTP 201 with
  `{"number": 42, "html_url": "https://github.com/.../issues/42", "state": "open", "specRef": "catalog-management"}`

### Requirement: Parameter validation for list endpoint

The list endpoint SHALL validate the `repo` parameter against the regex
`^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$` and SHALL reject `per_page`
values outside `[1, 100]`. Invalid values SHALL yield HTTP 400 with a structured error code
and SHALL NOT be forwarded to GitHub.

#### Scenario: Invalid repo format

- **WHEN** a user requests `GET /api/github/issues?repo=not-a-slug`
- **THEN** the endpoint SHALL return HTTP 400 with error code `repo_invalid_format`
- **AND** no request SHALL be made to GitHub

#### Scenario: per_page out of range

- **WHEN** a user requests `GET /api/github/issues?repo=ConductionNL/openregister&per_page=500`
- **THEN** the endpoint SHALL return HTTP 400 with error code `per_page_out_of_range`

### Requirement: Delegation to GitHubHandler

The controller SHALL delegate all outbound HTTP calls to
`OCA\OpenRegister\Service\Configuration\GitHubHandler::listIssues(...)` (for GET) and
`GitHubHandler::createIssue(...)` (for POST). Both handler methods are added as part of
this change and SHALL reuse the handler's existing Guzzle client, base URL, user-agent, and
rate-limit error translation. The controller SHALL NOT instantiate any HTTP client
directly.

#### Scenario: Controller uses the handler

- **WHEN** the controller receives a valid GET request
- **THEN** it SHALL call `GitHubHandler::listIssues` with the parsed arguments
- **AND** it SHALL NOT instantiate a separate HTTP client

### Requirement: App-level PAT usage for reads

`GitHubHandler::listIssues` SHALL read the Personal Access Token from `IAppConfig` key
`openregister::github_api_token`. When the token is present it SHALL be sent as an
`Authorization: Bearer <token>` header on the GitHub request. The token value SHALL NEVER
appear in any response body, any response header returned to the client, any log
statement, any exception message, or any cached payload.

#### Scenario: PAT present for read

- **WHEN** `openregister::github_api_token` is set
- **AND** an authenticated user requests open issues for a public repo
- **THEN** the outbound GitHub request SHALL carry the `Authorization: Bearer <TOKEN>` header
- **AND** the response returned to the client SHALL NOT contain the PAT value in any field

### Requirement: Graceful missing-PAT degradation for reads

When no app-level PAT is configured, the list endpoint SHALL return HTTP 200 with
`{"items": [], "hint": "github_pat_not_configured"}`. It SHALL NOT return HTTP 401, 403, or
500 for this condition, and SHALL NOT make an unauthenticated request to GitHub.

#### Scenario: No PAT configured for read

- **WHEN** `openregister::github_api_token` is empty or unset
- **AND** an authenticated user requests `GET /api/github/issues?repo=ConductionNL/openregister`
- **THEN** the endpoint SHALL return HTTP 200
- **AND** the response body SHALL be `{"items": [], "hint": "github_pat_not_configured"}`
- **AND** no outbound HTTP call SHALL be made to api.github.com

### Requirement: In-memory response cache for reads

GET responses SHALL be cached in-process with a 15-minute TTL keyed by the tuple
`(repo, state, sort, per_page)`. Cache hits SHALL NOT issue an outbound GitHub request and
SHALL include a response header `X-OpenRegister-GitHub-Cache: HIT` for observability. Cache
misses SHALL set the same header to `MISS`. The cache SHALL be instance-global (shared
across users) and not partitioned per user. The cache SHALL NOT apply to the POST endpoint.

#### Scenario: Second identical request is served from cache

- **WHEN** two identical GET requests for the same `(repo, state, sort, per_page)` arrive
  within 15 minutes
- **THEN** the second request SHALL be answered from cache
- **AND** the response SHALL include `X-OpenRegister-GitHub-Cache: HIT`
- **AND** only one outbound GitHub request SHALL have been made

#### Scenario: Cache expires after 15 minutes

- **WHEN** a cached response is older than 15 minutes and a new request arrives
- **THEN** the endpoint SHALL refetch from GitHub
- **AND** the response SHALL include `X-OpenRegister-GitHub-Cache: MISS`

### Requirement: Rate-limit handling for reads

The list endpoint SHALL translate GitHub rate-limit responses into a structured 429 for the
client. When GitHub responds with HTTP 403 carrying the `X-RateLimit-Remaining: 0` header,
or with HTTP 429, the endpoint SHALL return HTTP 429 to the client with a structured body
of the form `{"error": "github_rate_limited", "reset_at": "<ISO-8601 timestamp>"}`. It
SHALL derive `reset_at` from GitHub's `X-RateLimit-Reset` header. The endpoint SHALL NOT
retry automatically within the same request.

#### Scenario: GitHub rate-limits the proxy

- **WHEN** GitHub returns 403 with `X-RateLimit-Remaining: 0`
- **THEN** the proxy SHALL return HTTP 429 to the client
- **AND** the body SHALL contain `error: github_rate_limited` and a `reset_at` ISO-8601
  timestamp derived from `X-RateLimit-Reset`

### Requirement: PAT never leaks

Both the user-level and app-level PAT values SHALL NOT appear in any OpenRegister log file
(app.log, nextcloud.log, exception traces), any response body or header returned to the
client, any cached value in the response cache, or any error thrown from the handler. A
PHPUnit test SHALL assert this by checking the full response payload and log buffer for
the placeholder token string `YOUR_API_KEY_HERE` after exercising success, error, and
rate-limit paths with that placeholder in place of a real token.

#### Scenario: PAT leakage check in tests

- **WHEN** the PHPUnit test suite runs the success path, the rate-limit path, and an error
  path using placeholder token `YOUR_API_KEY_HERE`
- **THEN** the placeholder value SHALL NOT appear in any intercepted log line
- **AND** the placeholder value SHALL NOT appear in any response body or response header

### Requirement: Authentication and authorization

Both endpoints SHALL require an authenticated Nextcloud user (no `#[PublicPage]`). They
SHALL carry `#[NoAdminRequired]` so any logged-in user can read the roadmap and submit
feature requests. `GET /api/github/issues` SHALL carry `#[NoCSRFRequired]` (pure read).
`POST /api/github/issues` SHALL NOT carry `#[NoCSRFRequired]` so Nextcloud's CSRF middleware
protects submissions.

#### Scenario: Unauthenticated read

- **WHEN** an anonymous request hits `GET /api/github/issues?repo=ConductionNL/openregister`
- **THEN** Nextcloud's middleware SHALL return HTTP 401 before the controller runs

### Requirement: OpenAPI documentation

Both endpoints SHALL be documented in OpenRegister's OpenAPI specification, including the
GET query parameters, the POST request-body schema, the response schemas (items + hint +
cache header on GET; created issue shape on POST), and the documented error cases
(400/401/403/412/429/503). The schema SHALL include an example POST payload using the
placeholder token `YOUR_API_KEY_HERE` where a PAT would appear in any auth-flow example
and the nil UUID `00000000-0000-0000-0000-000000000000` where an ID placeholder is needed.

#### Scenario: OpenAPI describes both endpoints

- **WHEN** the OpenAPI spec is regenerated
- **THEN** it SHALL include `/api/github/issues` with both GET and POST operations and
  their documented response statuses
