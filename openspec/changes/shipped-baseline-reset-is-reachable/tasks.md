# Tasks: shipped-baseline-reset-is-reachable

## 1. Service
- [x] 1.1 `ShippedBaselineResetService::preview()` and `reset()` for one schema and one part; an empty part is refused.
- [x] 1.2 Only the named part is written back through `SchemaMapper::update()`; every other part keeps its stored value (no normalisation of the rest).
- [x] 1.3 The audit row is written after the schema write (`resetToBaseline(record: false)` + `recordReset()`).

## 2. Surfaces
- [x] 2.1 `occ openregister:schema:reset-to-shipped <schema> <part> [--apply --actor=<uid>]`: preview by default; apply only with an admin actor.
- [x] 2.2 Admin routes: GET preview, POST apply, on `ShippedBaselineController`.

## 3. Tests
- [x] 3.1 Service test with the real `ShippedConfigurationGuard`: a stored schema lacking a shipped authorization rule; after the reset the rule is there, another local property edit is untouched, the audit row names the actor; preview writes nothing; empty part and no session refused.
- [x] 3.2 Command test: preview writes nothing; `--apply` without an admin actor refused; with one, applied.
- [x] 3.3 Controller test: preview and apply.

## 4. Follow-up
- [ ] 4.1 Live: run the command for learniq `learner-profile` `authorization.read` on the dev instance (needs the live instance; recipe in the PR body).
