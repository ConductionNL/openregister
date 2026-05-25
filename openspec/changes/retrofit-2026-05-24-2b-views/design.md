# Design — Retrofit Bucket 2b `views`

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Context

`/opsx-coverage-scan` on 2026-05-24 placed 45 `src/views/*.vue` methods into Bucket 2b — "code exists, no spec to point at." This change closes the spec gap for the genuinely-new-territory subset (11 methods, 2 capabilities) and explicitly drops the rest (15 scanner false positives, 19 methods that belong to existing capabilities and need their own retrofit runs).

## Why two capabilities, not one

The 11 in-scope methods split cleanly along user-role lines:

- **admin-list-views** describes the *administrator's* surface for managing entities in the openregister app. Every method here is bound to the `<NcAppContent>` master/detail shell and is repeated across seven near-identical index pages — a textbook shared-contract.
- **account-self-service** describes the *signed-in user's* surface for managing their own identity. Every method here lives under `src/views/account/sections/*` and talks to a dedicated `/api/user/me/*` endpoint family.

Lumping them under one "views" capability would have produced a spec that means nothing — the cluster name from Bucket 2b is a directory, not a behavior. Splitting into two role-anchored capabilities gives each REQ a clear actor and a clear API surface.

## Why we did not extend an existing capability

We considered:

- **auth-system** — covers inbound authentication and token validation. The account-self-service flows are *outbound from the user's UI* and operate on the user's own identity, not on inbound auth decisions. Mixing the two would muddy auth-system's clear scope (which is already implemented and reviewed).
- **tenant-lifecycle** — covers organisation membership and rebasing. The account page is per-user, not per-organisation; the rebase dialog lives in a different settings section and is already dropped onto `tenant-lifecycle`.
- **production-observability / built-in-dashboards** — covers metrics and stats overlays, not entity-list-management UI.

No existing capability had an organic home for the seven `*Index.vue` master/detail pattern or the four `account/sections/*` user-self-service methods. Both are genuinely new territory.

## Drop list rationale

Three filters were applied to the raw 45-method scan:

1. **Scanner false positives (15 drops)** — every `method: "if"` entry is the coverage scanner mis-parsing a Vue template/script `if (...)` branch as a method. Fix belongs in the scanner, not in any spec.
2. **Methods belonging to existing capabilities (19 drops)** — see the table in `proposal.md`. Each one will need its own retrofit run against its owning capability, but mixing them in here would violate the "one capability per cluster" rule.
3. **Genuinely new (11 kept)** — split into the two capabilities above.

## REQ granularity calls

- `toggleSelectAll` and `toggleSidebar` are two separate REQs (REQ-001, REQ-002) because they describe two independent observable behaviors that happen to coexist in some views. Collapsing them would make either REQ harder to test in isolation.
- `mounted()` soft-refresh is a third REQ (REQ-003) rather than a sub-bullet of REQ-001 because it has a distinct contract (no spinner, fire-and-forget) and is observable across all seven views, not just the ones with bulk selection.
- For account-self-service, password+deactivation are grouped under one REQ-001 because they share the "form-section with API-call + inline feedback" pattern; tokens+avatar are grouped under REQ-002 because they share the "section initialiser + UI affordance" pattern. Splitting further would have produced four single-method REQs that say roughly the same thing.

## Scope guard

This retrofit run is *annotation-only*. No production code changes. No "while we're in here" refactors. If a method's observable behavior looks buggy, the Notes section in the relevant spec flags it for future tightening — the spec does not silently re-describe it as the correct behavior.

## Source

`openspec/coverage-report.md` generated 2026-05-24. Batch input: `/tmp/or-scan/rspec-2b-views.json` (45 methods, 32 files). Retrofit playbook: `.github/docs/claude/retrofit.md`.
