# Design: add-github-issue-proxy

## Context

OpenRegister already ships `lib/Service/Configuration/GitHubHandler.php` (per
ADR-011) for GitHub integrations elsewhere in the codebase. It owns the
Guzzle client, user-agent, retry/rate-limit machinery, and the existing PAT
stores (`openregister::github_token` IConfig per-user;
`openregister::github_api_token` IAppConfig app-level).

This change extends that handler with `listIssues()` + `createIssue()` and
exposes them via a new `GitHubIssuesController` so the UI half of the
project (`add-features-roadmap-menu`) can render a Roadmap tab and let
authenticated users file feature requests. No new schemas, registers, HTTP
clients, or secret stores are introduced.

## Goals

- Two endpoints under `/api/github/issues` (GET read + POST create), reusing
  the existing handler + PAT stores; sensitive response fields stripped.
- Repo allowlist via `openregister::github_repo` (REQUIRED); admin opt-out
  via `openregister::features_roadmap_enabled` (defaults `true`).
- OR semantics on multi-label filter (issue per-label, dedupe by number).
- Per-user rate limits on BOTH paths; APCu primary, distributed-cache
  fallback, fail-closed 503 when no backend exists.
- 15-min in-memory cache for GET, keyed by `(repo, state, sort, per_page,
  labels)`.
- Display-name / instance-URL sanitisation on the server-PAT fallback
  attribution prefix; `specRef` slug format validation.
- Audit logging for every server-PAT submission (INFO success / WARNING
  failure) — no PAT, no body, no title in the log payload.
- Graceful degradation on missing PAT / missing allowlist key.

## Non-Goals

- UI components, build-time spec extraction, Docusaurus integration — all
  in `add-features-roadmap-menu`.
- GitHub Discussions integration (chose Issues — see paired design D13).
- Fleet rollout to other Conduction apps.
- New secret-storage mechanism — reuses the two existing PAT stores.

## Decisions

### D1. Runtime storage: none (thin proxy)

We do NOT persist GitHub issues into an OpenRegister register, nor do we
persist a per-instance copy of features. Alternatives considered: nightly
sync into a register (drift + sync worker + duplicate data); anonymous read
only (60 req/hr per IP — exhausts fast). **Chosen:** thin cached proxy for
roadmap reads, direct proxy for submissions. Zero new data model; minimises
blast radius.

### D2. GitHub read auth: reuse `openregister::github_api_token` (IAppConfig)

Per-user PAT only for reads → unworkable UX (every user must configure
something to see public data). Anonymous → too low. **Chosen:** the
admin-configured app-level PAT for reads; admin configures once, all users
benefit.

### D9. Cache TTL: 15 minutes in-memory, global (per-instance)

Per-user cache rejected (same data for everyone). 1-hour rejected
(roadmap engagement benefits from freshness). 1-minute rejected (wastes
rate-limit budget). **Chosen:** 15-min in-memory cache, keyed by `(repo,
state, sort, per_page, labels)`, global per instance, response header
`X-OpenRegister-GitHub-Cache: HIT|MISS`.

### D10. Graceful degradation when PAT/allowlist is missing

GET returns `200 { items: [], hint: "github_pat_not_configured" }` (and
respectively `github_repo_not_configured`) rather than 403/500 — keeps the
Features tab unaffected even when GitHub access is broken. POST returns
503 with the same hint codes plus admin-remediation context. Neither
endpoint leaks the PAT.

### D14 (backend half). Authorship fallback: user-PAT preferred, server-PAT fallback with attribution

`createIssue` prefers `openregister::github_token` (IConfig per-user) so
authorship survives. On absence falls back to
`openregister::github_api_token` (IAppConfig) AND prefixes the body sent
to GitHub with:

```
> Submitted by **<nc_user_display_name>** via <nc_instance_url>

---

```

so traceability survives. Display name + instance URL are SANITISED
before embedding to prevent markdown injection or block-termination
(strip `\r`, `\n`, `*`, `_`, `[`, `]`, `(`, `)`, `` ` ``, `<`, `>`, `\`
→ spaces, truncate to 80 chars; non-https URL → drop URL, use literal
"via Nextcloud OpenRegister"). When `specRef` is provided, the body is
ALSO suffixed with `` \n\n---\nRelated capability: `<specRef>`\n `` AND
the issue carries a `specRef:<slug>` label.

### D20. Endpoint CSRF posture

GET `/api/github/issues` carries `#[NoCSRFRequired]` (pure read, no
mutation). POST `/api/github/issues` does NOT carry it — CSRF MUST
apply because the endpoint mutates external state (creates a GitHub
issue on behalf of the user).

### D21. Submission rate limit: APCu-backed, 1 per user per 60s

Database-backed rate limiter rejected (heavy; rate limit is ephemeral
by design). None rejected (obvious abuse path on server-PAT fallback).
**Chosen:** APCu key `openregister.feature_submission:<user_id>` with
60s TTL; return 429 with `{error: "rate_limited", retry_after}` when
exceeded. APCu-unavailable fallback: route through
`ICacheFactory::createDistributed()` / `createLocking()`; when neither
is available, fail closed with 503 `rate_limiter_unavailable`.

### D23. Roadmap issue filter: `labels=enhancement,feature` (OR semantics)

The proxy's GET endpoint accepts an optional `labels` query parameter
(comma-separated, ≤ 8 entries, each matching `^[a-z][a-z0-9_-]*$`,
≤ 50 chars). The Roadmap tab in the consuming UI hardcodes
`labels=enhancement,feature`. GitHub's native `labels=a,b` is AND
semantics; we need OR ("enhancement OR feature"), so for multi-label
requests the proxy issues one GitHub call per label and merges by
issue `number`. Merged result is sorted by `reactions-+1` desc before
the per-page truncate. The cache key includes the (sorted) labels list
so different filters don't collide.

## Risks / Trade-offs

- **GitHub read rate-limit exhaustion.** App-level PAT lifts the cap
  to 5000/hr; the 15-min cache further reduces real calls to a handful
  per day per instance. Endpoint maps GitHub 429 / `X-RateLimit-Remaining:
  0` to HTTP 429 `{error: "github_rate_limited", reset_at: "<ISO-8601>"}`
  for UI consumption.
- **PAT leakage.** Mitigated by sensitive-field stripping on responses,
  never logging the PAT or the body/title, and the PHPUnit `YOUR_API_KEY_HERE`
  leak assertion covering every response body, header, log, and cache entry.
- **Spam via server-PAT fallback.** Mitigated by per-user rate limit +
  audit log + `repo` allowlist + admin opt-out flag.

## Migration Plan

This is a purely additive backend change. No data migration. The two new
IAppConfig keys (`openregister::github_repo` + `openregister::features_roadmap_enabled`)
must be set by an admin before the feature is exercised — when unset both
endpoints return the documented graceful-degraded responses, so existing
deployments are unaffected.
