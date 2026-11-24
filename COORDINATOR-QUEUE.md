# COORDINATOR-QUEUE.md: what only the coordinator can do, collected while Ruben's laptop is in standby (24 Nov 2026 20:00 CET)

The coordinator is offline. Lanes keep going on their own: leases decide who does what, FINISH-ORDER.md DECISIONS are decided, PERF-HANDOFF.md and the openspec changes are the work. Nobody waits for the coordinator.

When a step needs the coordinator (the cloud permission check refuses it: release-bump merges, merging a nextcloud-vue or other dependency PR you built, anything --admin outside your own app's development), do NOT retry it and do NOT stop: append ONE line here and carry on with other work.
Format: `- <UTC time> <lane> <repo>#<pr> <what is needed, e.g. "merge: dependency for dossiq change X, tests green, mergeable">`
Before appending, merge main/development into that PR's branch if it conflicts (never rebase), so it is mergeable when the coordinator sweeps.
A product or access-control question that FINISH-ORDER.md DECISIONS does not answer: append it here as a question with 2-3 options and your recommendation, skip that task, carry on.

## Queue
- 19:06Z help-opencatalogi-1 opencatalogi#2969 merge: publication-required-at-publish 1.3/1.4/2.1/2.2; local green (PHPUnit same failing set as development, l10n clean, static analysis clean on touched files), but hydra diff-check.sh was refused by the cloud permission check, so run it and merge
- 2026-11-24T19:19Z b-larpinq ConductionNL/larpinq#1177 merge: registration-cancel-transfer-refund 2.3 (shillinq refund/credit commands, closes larpinq#1163), local green at 580b1472 (diff-check GREEN, PHPUnit 1436/0, phpstan OK, lint 0 errors; psalm needs PHP>=8.3.16), up to date with development, mergeable; squash merge refused by this session's permission check
