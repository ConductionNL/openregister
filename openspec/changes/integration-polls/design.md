# Design: Integration — Polls

> Umbrella decisions apply.

## Approach

`PollService` wraps NC Polls REST API. `PollsProvider` delegates. Tab surfaces poll lifecycle (draft → open → closed), tally, and user vote status.

## Architecture Decisions

### AD-1: Tally rendered compactly, not as full chart

**Decision**: Dashboard surfaces show headline tally (e.g., "7 yes / 3 no / 2 abstain"). Detail-page surface shows a mini bar chart. Full statistics live in Polls app.

**Why**: Dashboards need density. A bar chart is over-rendering for a single poll entry among dozens.

### AD-2: User's own vote highlighted

**Decision**: The tab highlights the current user's vote in each linked poll with a clear indicator.

**Why**: "Did I vote yet?" is the most common question for poll participants.

## Files Affected

### Backend (new)
- `lib/Service/PollService.php`, `lib/Controller/PollsController.php`, `lib/Db/PollLink.php`, `lib/Db/PollLinkMapper.php`, migration, `PollsProvider`, tests

### Backend (modified)
- `lib/AppInfo/Application.php`, `appinfo/routes.php`

### Frontend (new)
- `CnPollsTab/*`, `CnPollsCard/*`, `src/integrations/builtin/polls.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| Polls API may not expose per-user vote without elevated perms | Use Polls' own access model; fall back to "You voted: private" if API doesn't reveal |
| Ranked-choice polls render awkwardly | First iteration supports yes/no/abstain only; ranked as future enhancement |
