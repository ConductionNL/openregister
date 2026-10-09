# openregister: real state of development (measured 2026-10-08)

Method: development push runs of code-quality.yml, newest first (30 runs, 2026-10-06 to 2026-10-08), first
conclusion per job that is not `skipped`. Newest run 37790180294 (3b5f7e8b) was still queued, so not counted.
The repo has no nightly workflow of its own; the fleet drift sweep dispatches code-quality.yml on development
daily at about 03:20, and that dispatch is the only run where Playwright executes (push runs path-filter it).

## Not green

| job | real state | run / job | commit | cause |
|---|---|---|---|---|
| E2E Tests (Playwright) | failure, every dispatch since at least 2026-09-17 | 37722388311 / 113133853952 | 0ebf228a | 94 failed, 1 flaky, 241 did not run, 95 passed, 38 min global timeout hit |
| Quality Report | failure | 37722388311 / 113146358090 | 0ebf228a | aggregator, red because E2E is red |

All other jobs green on their newest real run: every PHP Quality leg, Vue Quality, Frontend Check legs, Frontend
Tests, Hydra Gates, Newman, SBOM, security, licence, REUSE, info.xml (run 37751623075 @ 0b763aff) and all 8
PHPUnit cells, PHP 8.3/8.4 x NC stable32-35 pgsql (run 37712439458 @ 0ebf228a).

Never ran in the window, by design of the caller config: Coverage Baseline Protection and Features Check
(pull_request only), app:check-code, Journeydoc Capture and Hydra Gates (axe) (inputs not enabled).

## E2E failure clusters (from job 113133853952)

1. Maintenance mode cannot be left. `MaintenanceModeMiddleware::ALWAYS_REACHABLE` names only
   `OperationsConsoleController`; bd7fe3470d (2026-09-20) moved the maintenance and consistency endpoints to
   `OperationsMaintenanceController` / `OperationsConsistencyController`, so once operations-console.spec.ts
   enters maintenance, the DELETE that leaves it gets 503 and every later spec fails on "maintenance-mode".
   Product bug, not only a test bug: an admin cannot leave maintenance over HTTP.
2. "Schema not found: 'N'" on object create, about 15 tests (before the maintenance cascade).
3. Single failures, to triage one by one: api-surface (2), archiving-process, audit-shipping (activity name),
   code-list-lifecycle filter, computed-values trial, cross-register-tenancy (3), data-subject-rights (group
   key), export-profile (enumNames), field-rules-by-state, flow-controls, flow-lock-nodes, import-preview,
   instance-hardening (3), note-edit-history, notification-routing, object-as-hinge.
EOF
## Progress (2026-10-08/09)

Reproduced on a throwaway NC 34 instance (containers green-or-nc / green-or-db, 127.0.0.1:8101): run 1 on the
maintenance fix reproduced every pre-cascade failure exactly, and the UI specs (manifest-shell, spec-coverage)
passed once the maintenance cascade was gone.

| PR / branch | cause fixed |
|---|---|
| #4494 fix/dev-green-maintenance-reachable | maintenance mode could not be left (middleware allowlist) |
| #4496 fix/dev-green-vocabulary-enum-labels | enumNames / enumLabels refused by the property vocabulary, read by the export |
| fix/dev-green-e2e-fixtures | tenancy spec left owner/other in fixture orgs (the "Schema not found" cluster); 412 from missing OCS-APIRequest; elevation needs a real session; anon context; stale payload keys |
| fix/dev-green-bulk-save-cache | ObjectService::saveObjects read statistics keys nothing writes, so the list cache was never invalidated after a bulk save (import-preview) |

Needs a decision (validator exists and is unit-tested, save path is advisory, spec says refuse with 422):
field-rules-by-state (lifecycle-state-field-missing), notification-routing (role-unknown; mapper also never passes
`authorization` to the validator).

Session stopped by a rate limit at about 22:00; the machine rebooted, so run 1 and the first check:strict were
cut off and are being rerun.

## Status at hand-off (2026-10-09)

E2E on a throwaway NC 34 instance, CI config, all fixes: 42 failed / 103 did not run / 288 passed
(nightly before: 94 / 241 / 95).

| PR | what | proof |
|---|---|---|
| #4494 | maintenance mode can be left | unit before/after, operations-console 19/19, check:strict (9 env errors) |
| #4496 | enumNames/enumLabels accepted | unit before/after, Schemas dir 277 OK |
| #4506 | e2e fixtures (tenancy leak, CSRF header, anon contexts, session, _limit, payloads) | run 1 vs run 3, spec reruns |
| #4507 | recent lens table prefix | favourites 8/8 after |
| #4508 | folder handler resolves FileService lazily | unit + log; sharing still blocked by sharer decision |
| (no PR) fix/dev-green-bulk-save-cache | stats keys mismatch in saveObjects | no observable symptom: control without fix also correct |

Decisions needed: lifecycle state-field and notification role refusals (advisory vs 422), per-object deny has no
write path, shares on OR-owned folders ("Cannot increase permissions"), cross-register union needs magic mapping.
Not triaged: archiving nomination, audit purpose counts, code-list branch filter, delete window, export grant,
calendar VEVENT, party roles, publication view mint, query-related rows, reference-options, repeating groups,
rules engine, search facet, timeline kinds, several UI timeouts, features-roadmap config write, watchers block.
Disk fell below 5 GB at the end, so no further installs were done.
