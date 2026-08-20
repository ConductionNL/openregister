---
title: GitHub Issues Proxy (Features & Roadmap menu)
sidebar_position: 50
description: Admin guide for the GitHub Issues proxy backing the in-product Features & Roadmap surface — PAT scopes, repo allowlist, admin opt-out, audit logging
keywords:
  - Open Register
  - GitHub
  - Features
  - Roadmap
  - Admin
---

# GitHub Issues Proxy (Features & Roadmap menu)

OpenRegister exposes a thin, cached, server-side proxy over GitHub's Issues API that backs the in-product **Features & Roadmap** menu component shipped from `@conduction/nextcloud-vue`. This page documents the admin-facing knobs.

> **Scope.** Endpoints, request/response shapes, and error codes live in [openapi.json](https://github.com/ConductionNL/openregister/blob/development/openapi.json). The capability spec is at [`openspec/changes/add-features-roadmap-menu/specs/github-issue-proxy/spec.md`](https://github.com/ConductionNL/openregister/blob/development/openspec/changes/add-features-roadmap-menu/specs/github-issue-proxy/spec.md).

## What the proxy does

| Endpoint | Purpose |
|---|---|
| `GET /index.php/apps/openregister/api/github/issues` | Cached (15 min) list of GitHub issues for the configured repository, filterable by labels. |
| `POST /index.php/apps/openregister/api/github/issues` | Submit a new GitHub issue on behalf of the authenticated user (server-PAT-fallback when the user has no per-user PAT). |

## Configuration

The proxy reads three IAppConfig keys under the `openregister` app:

| Key | Type | Default | Purpose |
|---|---|---|---|
| `github_api_token` | string (sensitive) | empty | App-level PAT used for **read** requests + as a **fallback for write** requests when the user has no per-user PAT. |
| `github_repo` | string | empty | **Allowlist of one repository** in the form `<owner>/<repo>` (e.g. `ConductionNL/openregister`). Requests for any other repository are rejected with HTTP 403 `repo_not_allowed`. |
| `features_roadmap_enabled` | bool | `true` | Admin opt-out flag. When `false`, both endpoints return HTTP 403 `feature_disabled` and the menu component renders an "admin disabled" empty state. |

Set them via OCC:

```bash
docker exec -u www-data nextcloud php occ config:app:set openregister github_api_token --value=ghp_REPLACE_ME --sensitive
docker exec -u www-data nextcloud php occ config:app:set openregister github_repo --value=ConductionNL/openregister
docker exec -u www-data nextcloud php occ config:app:set openregister features_roadmap_enabled --value=true
```

The `--sensitive` flag on `github_api_token` is **required** — it tells Nextcloud to redact the value in admin dumps + `occ config:app:get` listings.

## Required PAT scope

The minimum GitHub OAuth scope is **`public_repo`** — nothing broader. Specifically:

- The proxy reads from `GET /repos/{owner}/{repo}/issues` and `GET /repos/{owner}/{repo}/issues/{n}/reactions`. Both are covered by `public_repo`.
- The proxy writes to `POST /repos/{owner}/{repo}/issues` and applies a `specRef:<slug>` label on submissions. Both are covered by `public_repo`.

**Do not grant broader scopes.** A PAT with `repo` (full private-repo control), `admin:org`, `delete_repo`, or `workflow` violates least-privilege and dramatically increases the blast radius if the PAT leaks. The code cannot detect or reject an over-privileged PAT — that discipline is yours.

**Prefer GitHub fine-grained PATs over classic PATs** because they scope to a single repository in addition to scoping by permission. With a fine-grained PAT pinned to the same repository as `github_repo`, a token leak is contained to that one repo.

## Token lifecycle

| Aspect | Recommendation |
|---|---|
| **PAT format** | Fine-grained PAT scoped to the single configured repository. Classic PATs work but are wider-blast. |
| **Expiry** | Fine-grained PATs require explicit expiry. Pick 90 days as the default — short enough to limit damage on a quiet leak, long enough to avoid rotation churn. |
| **Rotation cadence** | 90 days for the app-level PAT. User PATs are rotated by users themselves; no system-imposed cadence. |
| **Revocation on suspected compromise** | (1) Revoke the PAT on GitHub immediately. (2) Replace it in OpenRegister via the admin settings UI or `occ config:app:set --sensitive`. (3) Review the audit-log entries described below for unexpected `repo` values or submission volume. |

There is no built-in expiry-detection job. The proxy's `validateToken()` helper is available; an admin-facing health-check (periodic background job or settings-page status indicator) is a future enhancement.

## Audit logging

Server-PAT-fallback submissions emit one structured audit-log entry per submission to Nextcloud's `app.log`:

- **Success**: INFO level, with fields `{user_id, repo, issue_number, specref, timestamp}`.
- **Failure**: WARNING level, with the same fields plus `{error_code, github_status}`.

User-PAT-path submissions emit **no** openregister entry — those are auditable directly on GitHub under the user's identity.

The audit entries never contain the PAT value, the issue body, the issue title, or the attribution prefix. The fields are intentionally minimal — enough for compliance / incident response, narrow enough to never leak content.

Sample structured log entry (success path):

```
2026-05-15T09:42:17+00:00 INFO openregister: [GitHubHandler] Server-PAT submission succeeded
  {
    "user_id": "alice",
    "repo": "ConductionNL/openregister",
    "issue_number": 1247,
    "specref": "catalog-management",
    "timestamp": "2026-05-15T09:42:17+00:00"
  }
```

To search the audit trail:

```bash
docker exec nextcloud grep -E "Server-PAT submission (succeeded|failed)" /var/www/html/data/nextcloud.log | jq .
```

## Rate limits

| Scope | Default | Where |
|---|---|---|
| Per-user submissions | 1 per 60 seconds | APCu (with ICache distributed fallback — see task 1.18 backlog) |
| Per-user GET cache misses | 10 distinct cache keys per 5 minutes | Distributed cache (`openregister_github_issues_get_rate`) |
| GitHub-side (read) | 5000/hr authenticated, 60/hr anonymous | GitHub. Translated to `429 github_rate_limited` + `reset_at`. |

Cache hits do not count against the per-user GET counter. The 5-minute rolling window resets on each request after expiry.

## Hardening already in place

These guards are enforced unconditionally and do not need admin tuning:

- **Repo allowlist** (task 1.14) — collapses the user-supplied `repo` attack surface to the single value in `github_repo`.
- **Display-name + URL sanitization** (task 1.15) — strips 12 markdown / DOM characters from the embedded display name, truncates to 80 chars, validates the instance URL is `https://` or `http://localhost` before embedding.
- **specRef format validation** (task 1.16) — rejects slugs that don't match `^[a-z0-9][a-z0-9-]*[a-z0-9]$` or exceed 80 chars.
- **`sort` allowlist** (task 1.20) — constrained to `{reactions-+1, created, updated, comments}`.
- **`labels` validation** (task 1.20b) — max 8 entries, each ≤ 50 chars, each matching `^[a-z][a-z0-9_-]*$`.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Roadmap tab shows "Ask your admin to configure the GitHub PAT" | `github_api_token` is unset. |
| Roadmap tab shows "Roadmap not configured" | `github_repo` is unset. |
| Roadmap tab shows "disabled by your administrator" | `features_roadmap_enabled` is `false`. |
| Roadmap tab loops on rate-limit toast | Per-user GET cache-miss budget exhausted — wait 5 min, or admin can adjust the cache TTL if the load justifies. |
| Submissions all show as the bot account on GitHub | Users have no per-user PAT and fall back to the server PAT. They should configure their own PAT in OpenRegister settings to get authorship attribution. |
| Server-PAT submission entries missing from `app.log` | Submission may have used the user-PAT path (no openregister entry by design — check GitHub audit). Or the GitHub call failed before reaching the audit emit — check the logs around the same timestamp. |

## Related

- Frontend component family: [`@conduction/nextcloud-vue`](https://github.com/ConductionNL/nextcloud-vue) — `CnFeaturesAndRoadmapLink`, `CnFeaturesAndRoadmapView`, `CnRoadmapTab`, `CnSuggestFeatureModal`.
- Component design + decisions (D1–D23): [`openspec/changes/add-features-roadmap-menu/design.md`](https://github.com/ConductionNL/openregister/blob/development/openspec/changes/add-features-roadmap-menu/design.md).
- API contract: [`openapi.json`](https://github.com/ConductionNL/openregister/blob/development/openapi.json) (search for `github-issues` operations).
