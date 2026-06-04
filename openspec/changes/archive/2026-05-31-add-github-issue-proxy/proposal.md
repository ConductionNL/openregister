# Proposal: add-github-issue-proxy

`kind: feature` per ADR-032 — adds a new backend capability
(controller + service methods + new IAppConfig keys) to OpenRegister.

## Summary

Add a thin, cached, server-side proxy over GitHub's issues API to OpenRegister
so every Conduction app can (a) **read** open issues for its own repo (sorted
by reactions, filterable by label, returned with sensitive fields stripped),
and (b) **create** new feature-request issues on behalf of authenticated users
without leaving the product. The proxy extends OpenRegister's existing
`GitHubHandler` (per ADR-022 — consume existing abstractions, don't fork) and
reuses both existing PAT stores: per-user `openregister::github_token`
(IConfig) preferred for authorship, app-level `openregister::github_api_token`
(IAppConfig) as fallback for reads and as fallback for writes with an
authorship-attribution prefix.

This is the backend half of what was originally one bundled change. The
UI/build-time half ships separately as `add-features-roadmap-menu` and
depends on this capability. See spec depends-on chain in
`specs/github-issue-proxy/spec.md`.

## Motivation

Today operators and end-users have no in-product way to suggest features.
GitHub Issues is already the canonical roadmap source for every app, but
non-developers don't visit it. Reading from + writing to GitHub through OR
unlocks the in-app surface AND deliberately keeps the secret-handling, rate
limiting, and credential model in one place — OpenRegister — rather than
re-implementing it per app.

## Affected Projects

- [x] Project: openregister — new `GitHubIssuesController`, two new methods
  on existing `GitHubHandler`, new APCu rate-limit + cache wiring, new
  IAppConfig keys (`openregister::github_repo`, `openregister::features_roadmap_enabled`),
  admin-docs section.
- [ ] Project: nextcloud-vue, hydra — no source changes here; downstream
  `add-features-roadmap-menu` consumes this capability.

## Scope

### In Scope

- One new capability spec (`github-issue-proxy`) — see `specs/`.
- Two endpoints under `/api/github/issues` (GET read + POST create).
- Repo-allowlist enforcement (`openregister::github_repo`), admin opt-out
  flag (`openregister::features_roadmap_enabled`), per-user rate limits for
  both read (cache-miss) and write paths, sensitive-field stripping on
  response payloads, OR semantics for multi-label filter.
- Display-name / instance-URL sanitisation, `specRef` slug format
  validation, sort allowlist, labels-parameter validation.
- Audit logging for server-PAT-fallback submissions (success + failure).
- APCu-unavailable graceful fallback through Nextcloud's
  `ICacheFactory::createDistributed()` / `createLocking()`.
- PHPUnit coverage for every documented success/error path including
  PAT-leak assertions, OpenAPI documentation, admin docs for PAT scopes +
  token-lifecycle + new IAppConfig keys.

### Out of Scope

- The Vue UI, the build-time spec-to-features manifest, the Docusaurus
  package — all shipped in the paired `add-features-roadmap-menu` change.
- GitHub Discussions integration (user explicitly chose Issues).
- "Accept feature → specter spec proposal" automation.
- Fleet rollout of the consuming UI to other Conduction apps.

## Impact

- **Extends existing abstractions** — reuses `GitHubHandler` (ADR-011) and
  both existing PAT stores; no new HTTP client, no new retry/rate-limit
  machinery, no new secret store.
- **First OR feature that writes to an external system on behalf of an
  end-user** — traceability guaranteed by attribution prefix on server-PAT
  fallback path and by INFO audit log on every server-PAT submission.
- **No new schemas, registers or databases** — proxy is stateless aside
  from a 15-min in-memory response cache (GET) and APCu rate-limit keys.
- **Two new IAppConfig keys** — `openregister::github_repo` (repo
  allowlist, REQUIRED for both endpoints to function) and
  `openregister::features_roadmap_enabled` (admin opt-out, defaults
  `true`).
- **Graceful degradation** — no PAT → GET returns 200 with empty items
  + hint, POST returns 503 with admin-remediation hint; no cache backend
  → fail-closed 503 `rate_limiter_unavailable`.
