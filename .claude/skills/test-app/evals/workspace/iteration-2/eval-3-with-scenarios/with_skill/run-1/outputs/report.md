# CANNOT_TEST — procest app missing from repo

**Date:** 2026-04-13
**App:** procest
**Mode:** Single (with scenarios)
**Environment:** http://nextcloud.local (reachable, HTTP 200)
**Status:** CANNOT_TEST

## What the agent did right

The with-skill agent correctly:
1. Read `test-app/SKILL.md` end-to-end
2. Followed Step 0 (select app: procest)
3. Executed Step 2 (environment probe) — picked `http://nextcloud.local` (HTTP 200), correctly skipped `http://localhost:8080` (000). **The URL fallback patch worked.**
4. Followed Step 2.5 (load test scenarios) — looked in `/home/wilco/hydra/procest/test-scenarios/` and across the whole repo
5. Stopped and reported CANNOT_TEST when the app and scenarios were not found, instead of fabricating

This is **good skill behavior**. The skill correctly halted on missing prerequisites.

## Why testing was blocked

The `procest` app does not exist in the hydra repository. The hydra repo root contains: `.claude/`, `.git/`, `.github/`, `agents/`, `docs/`, `images/`, `img/`, `manifests/`, `openspec/`, `personas/`, `scripts/`, `secrets/`, `vendor/` — no `procest` directory.

This is a test-data issue, not a skill issue. The eval was constructed assuming `procest` was an app in this repo; it isn't.

## Scenario Results

| Scenario ID | Status | Reason |
|---|---|---|
| (none found) | CANNOT_TEST | `procest/test-scenarios/` does not exist in the repository |

## Conclusion

To make this eval runnable, either:
- Change the eval prompt to target an app that does exist (e.g. `openregister`)
- Add a `procest/` directory with test-scenarios files
- Mock the app structure for eval purposes
