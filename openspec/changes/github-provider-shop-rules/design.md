## Context

The credential-broker provider catalogue (`lib/Settings/credential-providers.json`)
is a runtime-immutable security control (credential-broker design D2): its
`allowRules[]` bound exactly which method + path any credential can ever reach on a
host-locked base. New providers and widened rules ship only through a reviewed
release. This change is such a release step: it widens the existing `github`
provider so OpenBuild's redesigned app shop can search GitHub, resolve the PAT's
login, and create repositories when publishing an app — all through the broker,
with the token held in Doriath custody, instead of a token pasted into OpenBuild.

Source of truth for the broker mechanics (verified against HEAD, not assumed):

- **Rule matching** — `CredentialBrokerService::assertRuleAllowed()` matches with
  exact upper-cased method plus `fnmatch($rulePattern, $matchPath)`. No
  `FNM_PATHNAME` flag is passed, so `*` matches path separators too.
- **Path normalisation** — `normalisePath()` rejects `..` traversal and
  protocol-relative paths, single-decodes, and strips the query string BEFORE rule
  matching. So `GET /search/repositories?q=topic:nextcloud` matches the pattern
  `/search/repositories`.
- **Host-lock** — `resolveAndLockUrl()` appends the caller path to `baseUrl`
  (`https://api.github.com`) and enforces host equality (`api.github.com`).
- **Auth injection** — `injectAuth()` sets `Authorization: token {secret}` and
  discards any caller-supplied `Authorization`/`Host` header. Unchanged here.

Current `github` allow-rules at HEAD (catalogue v1.1.0):

```jsonc
[
  { "method": "GET", "pathPattern": "/repos/*" },
  { "method": "GET", "pathPattern": "/user/repos" },
  { "method": "PUT", "pathPattern": "/repos/*/contents/*" },
  { "method": "POST", "pathPattern": "/repos/*/git/*" }
]
```

## Decisions

### GH-1 — Add exactly six rules, no more

The redesigned shop flow needs precisely: search, identity, repo-create (user +
org), branch-ref update on re-publish, and discovery-topic write on first publish.
The minimal additive set is therefore:

```jsonc
{ "method": "GET",   "pathPattern": "/search/repositories" },
{ "method": "GET",   "pathPattern": "/user" },
{ "method": "POST",  "pathPattern": "/user/repos" },
{ "method": "POST",  "pathPattern": "/orgs/*/repos" },
{ "method": "PATCH", "pathPattern": "/repos/*/git/refs/*" },
{ "method": "PUT",   "pathPattern": "/repos/*/topics" }
```

The `PUT /repos/*/topics` rule exists because OpenBuild's shop discovery contract is
the `openbuild-app` GitHub topic: publishing an unlinked app creates the repo and
must tag it via `PUT /repos/{owner}/{repo}/topics`. The existing
`PUT /repos/*/contents/*` rule is anchored under `/contents/` and does not match the
topics endpoint.

The `PATCH` rule exists because the Git Data API creates refs with `POST
/repos/{owner}/{repo}/git/refs` (already covered by the existing `POST
/repos/*/git/*` rule) but updates an existing branch ref only via `PATCH
/repos/{owner}/{repo}/git/refs/{ref}` — without it, OpenBuild could publish an app
to a fresh repo but never push a second commit to the same branch. The pattern is
anchored under `/git/refs/`, so repository-settings `PATCH /repos/{owner}/{repo}`
stays denied.

Nothing for issues, pull-requests, workflows, webhooks, deletes, or org/team
administration — those are permanent surface for a flow that does not use them, and
each would be a cheap follow-up reviewed release if a future feature needs it.

### GH-2 — No explicit GET contents/git rule is added (verified redundant)

The task brief asked to verify whether `GET /repos/*` already covers
`GET /repos/{owner}/{repo}/contents/{path}` and `GET /repos/{owner}/{repo}/git/*`
under the broker's glob semantics. It does. Because `assertRuleAllowed()` calls
`fnmatch('/repos/*', $path)` with no `FNM_PATHNAME` flag, the `*` spans slashes.
Verified at HEAD:

```
fnmatch('/repos/*', '/repos/owner/repo/contents/path/to/file.json') === true
fnmatch('/repos/*', '/repos/owner/repo/git/trees/main')            === true
```

So repo reads (including contents and git-data reads) are already permitted by the
existing `GET /repos/*` rule. Adding explicit GET contents/git rules would be dead,
redundant surface. **No such rule is added.**

### GH-3 — `GET /user` is a distinct rule from the existing `GET /user/repos`

The broker's `fnmatch` uses the pattern as a literal glob, so `/user` matches only
the exact path `/user` and does NOT match `/user/repos` (verified:
`fnmatch('/user', '/user/repos') === false`). The already-shipped `GET /user/repos`
rule therefore does not authorise `GET /user`, and the new `GET /user` rule does not
broaden `/user/repos` matching. Both coexist as two exact-match rules.

### GH-4 — `POST /user/repos` and `POST /orgs/*/repos` for repo creation

GitHub creates a repository under the authenticated user with `POST /user/repos`
and under an organisation with `POST /orgs/{org}/repos`. The org rule uses the
`*` wildcard for the org login segment (`/orgs/*/repos`), matching the catalogue's
established wildcard-for-owner-segment convention (as in `/repos/*`). The user-repo
create path (`/user/repos`) is the same literal path as the already-allowed
`GET /user/repos` but a different method, so it is a separate rule — the broker
matches method + path together, so the GET rule never authorises the POST.

### GH-5 — Search path lives at `/search/repositories`

The GitHub repository-search endpoint is `GET /search/repositories` under the same
`https://api.github.com` base. Query parameters (`q`, `sort`, `per_page`, `page`)
are stripped by `normalisePath()` before matching, so the exact pattern
`/search/repositories` covers every search call regardless of its query string. No
wildcard is needed or added.

## Declarative-vs-imperative note (ADR-031)

Unchanged from credential-broker D2: the catalogue is a deliberate,
security-justified exception to declarative-first-in-the-register — a
runtime-immutable declarative JSON file in `lib/`, never API-writable. This change
widens one provider's rules in that file through the exact mechanism D2 prescribes
(code review + release). No imperative code, lifecycle, aggregation, notification,
relation, or widget behaviour is added; the change stays `kind: config`.

## Risks / Trade-offs

- [Surface growth] The `github` provider gains six new permitted calls. Each is
  scoped to the shop flow and justified above; none touches issues/PRs/workflows/
  webhooks/deletes. Repo creation (`POST /user/repos`, `POST /orgs/*/repos`) is the
  most consequential new capability — it lets an allow-listed app create repos under
  the token's account/orgs. This is the intended publish-an-unlinked-app behaviour;
  the token owner controls it by choosing which app to allow-list on the credential.
- [Org wildcard breadth] `/orgs/*/repos` matches any org login, and (because `*`
  spans slashes) technically also deeper paths like `/orgs/x/teams/y/repos`. GitHub
  has no such create endpoint, so this is not a real broadening; the practical
  surface is org-repo creation only. Tightening to a non-slash segment would require
  a broker-level `FNM_PATHNAME` change, which is out of scope and would affect every
  provider's existing rules.
- [Secret exposure] None added: the PAT lives behind the existing `CredentialStore`
  (Doriath custody); this file carries only the `token {secret}` template, never a
  token. Examples in specs/tasks use `YOUR_API_KEY_HERE` placeholders only.
- [Regression to other providers/rules] The change is additive; the existing four
  `github` rules and the whole `gitlab`/`doffin` entries stay byte-identical, and
  the acceptance criteria pin that.

## Migration Plan

1. Append the six `github` allow-rules + bump the catalogue `version` (apply task).
2. No data migration, no repair step, no schema change. Rollback = revert the file.
3. Operator/consumer flow after release: the GitHub PAT is stored once as an OR
   credential (provider `github`) with the secret held in Doriath custody, OpenBuild
   is allow-listed on that credential, and OpenBuild's shop calls the broker for
   search, identity, and repo-create/read/write.
