# Integration: Polls

## Problem

Formal decisions and group voting happen in NC Polls, disconnected from the object (case, decision record) they pertain to. Decidesk especially needs poll-decision crossover — a council vote must anchor to the motion object. Today this leaf is **partial** per the 2026-05-24 registry audit — the backend issues a direct DB query against `polls_polls` (session workaround) and returns real linked polls, but the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnPollsTab` / `CnPollsCard` (live vote tally). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Decidesk and Procest have no working integration UI path and reinvent vote-attachment locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: Direct DB query on `polls_polls` (session workaround) — returns real linked polls; should migrate to `OCA\Polls\Service\PollService` when feasible
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (live vote tally)
- **Target NC class(es)**: `OCA\Polls\Service\PollService` (replacing the direct DB query) or keep direct `polls_polls` query with documented session workaround
- **Storage strategy**: `link-table`
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **Primary consumer:** Decidesk (motions + votes), secondary: any workflow with formal decision gates

## Proposed Solution

`PollService` + `PollsController` + `PollsProvider` + `CnPollsTab` + `CnPollsCard`. Tab lists linked polls with status (open/closed), vote totals, the user's vote. Create-new and link-existing flows. Detail-page widget shows aggregated tally. Provider imports `OCA\Polls\Service\PollService` (preferred) — or retains the direct `polls_polls` query with a clear session workaround comment — and falls back to `IntegrationHealth::missingApp('polls')` when NC Polls is not installed.

## Scope

**In scope:** Backend service wrapping Polls, link table, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Poll authoring UI (Polls app owns); anonymous vote decryption; ranked-choice analysis beyond what Polls exposes.

## Acceptance criteria

- [ ] Polls tab appears when Polls installed + schema has `polls` in linkedTypes
- [ ] User can link existing poll or create new one from tab
- [ ] Tab shows poll status + tally + user's own vote
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'polls'` works
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\Polls\Service\PollService` import (or documented direct `polls_polls` query) for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('polls')` when NC Polls absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnPollsTab` + `CnPollsCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
