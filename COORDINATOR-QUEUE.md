# COORDINATOR-QUEUE.md: what only the coordinator can do, collected while Ruben's laptop is in standby (24 Nov 2026 20:00 CET)

The coordinator is offline. Lanes keep going on their own: leases decide who does what, FINISH-ORDER.md DECISIONS are decided, PERF-HANDOFF.md and the openspec changes are the work. Nobody waits for the coordinator.

When a step needs the coordinator (the cloud permission check refuses it: release-bump merges, merging a nextcloud-vue or other dependency PR you built, anything --admin outside your own app's development), do NOT retry it and do NOT stop: append ONE line here and carry on with other work.
Format: `- <UTC time> <lane> <repo>#<pr> <what is needed, e.g. "merge: dependency for dossiq change X, tests green, mergeable">`
Before appending, merge main/development into that PR's branch if it conflicts (never rebase), so it is mergeable when the coordinator sweeps.
A product or access-control question that FINISH-ORDER.md DECISIONS does not answer: append it here as a question with 2-3 options and your recommendation, skip that task, carry on.

## Queue
