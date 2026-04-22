# Integration: Polls

## Problem

Formal decisions and group voting happen in NC Polls, disconnected from the object (case, decision record) they pertain to. Decidesk especially needs poll-decision crossover — a council vote must anchor to the motion object.

## Context

- **Backend:** greenfield — wrap NC Polls REST API
- **Required NC app:** `polls`
- **Storage:** `link-table`
- **Depends on:** `pluggable-integration-registry`
- **Primary consumer:** Decidesk (motions + votes), secondary: any workflow with formal decision gates

## Proposed Solution

`PollService` + `PollsController` + `PollsProvider` + `CnPollsTab` + `CnPollsCard`. Tab lists linked polls with status (open/closed), vote totals, the user's vote. Create-new and link-existing flows. Detail-page widget shows aggregated tally.

## Scope

**In scope:** Backend service wrapping Polls, link table, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Poll authoring UI (Polls app owns); anonymous vote decryption; ranked-choice analysis beyond what Polls exposes.

## Acceptance criteria

- [ ] Polls tab appears when Polls installed + schema has `polls` in linkedTypes
- [ ] User can link existing poll or create new one from tab
- [ ] Tab shows poll status + tally + user's own vote
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'polls'` works
- [ ] Parity gate passes; nl+en done
