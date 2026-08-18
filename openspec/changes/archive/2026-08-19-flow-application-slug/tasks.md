## 1. Storage

- [x] 1.1 Add migration `lib/Migration/Version1Date<next-timestamp>.php` (next free timestamp after `Version1Date20260817120000`) adding `applicationSlug` to `openregister_flows` — `Types::STRING`, length 255, `notnull => false`, `default => null` — guarded by `hasTable('openregister_flows')` and `hasColumn('applicationSlug')`, mirroring `Version1Date20260812100000`'s `comment`-column migration.
- [x] 1.2 In the same migration, add index `or_flow_app_slug_idx` on `(applicationSlug, id)`, mirroring the existing `or_flow_app_idx` on `(app, id)`, guarded by `hasIndex('or_flow_app_slug_idx')` so it is a no-op on rerun.
- [x] 1.3 Add `applicationSlug` to `lib/Db/Flow.php`: protected property, `@method` getter/setter docblock entries, `addType('applicationSlug', 'string')` in the constructor, and the `jsonSerialize()` array.

## 2. Mapper

- [x] 2.1 Add `?string $applicationSlug = null` to `FlowMapper::findAllFlows()`, applied as `andWhere(eq('applicationSlug', ...))` only when non-empty — the same pattern already used for `$app`.
- [x] 2.2 Add the same parameter and predicate to `FlowMapper::countFlows()`.

## 3. Service

- [x] 3.1 Add `?string $applicationSlug = null` to `FlowService::findAll()` and `FlowService::count()`, passed straight through to the mapper (no organisation-scoping change — that stays as-is).
- [x] 3.2 Add `applicationSlug` to `FlowService::applyEditableFields()`'s plain-string allowlist (`setApplicationSlug`), alongside `name`/`description` — present-key-only, explicit-null-clears, same as the rest of that allowlist.

## 4. Controller

- [x] 4.1 In `FlowController::index()`, read `?applicationSlug=`, trim, and pass `null` through when empty/absent — same handling already used for `?app=` — forwarded to both `$this->flows->findAll()` and `$this->flows->count()`.

## 5. Tests

- [x] 5.1 Add `tests/Unit/Service/Flow/FlowApplicationSlugRoundTripTest.php`, mirroring `tests/Unit/Service/Flow/FlowCommentRoundTripTest.php`: `applicationSlug` is stored and serialised; a partial update that omits the key leaves it unchanged; an explicit `null` clears it.
- [x] 5.2 Add `tests/Unit/Db/FlowTest.php` coverage that a `Flow` with no `applicationSlug` serialises it as `null` (the "stays fully valid without one" claim from the spec delta).
- [x] 5.3 Add `tests/Unit/Controller/FlowControllerTest.php` coverage for `index()`: `?applicationSlug=` is forwarded to `FlowService::findAll()`/`count()`; an absent/empty value forwards `null` (unfiltered, matching current behaviour); `?app=` and `?applicationSlug=` together are both forwarded on the same call.
- [x] 5.4 Run `vendor/bin/phpunit` for the four touched/added test files locally and confirm green.

## Acceptance Criteria

- A flow created or updated with no `applicationSlug` in the payload behaves exactly as it does today at every layer (storage, read, list, filter).
- `GET /apps/openregister/api/flows?applicationSlug=<slug>` returns only flows whose stored `applicationSlug` equals `<slug>`; omitting the parameter returns every flow visible to the caller, unchanged from current behaviour.
- `?app=` and `?applicationSlug=` compose as an AND, matching the migration's precedent (`Version1Date20260812100000`) and the mapper's existing `app`-filter shape.
- `FlowEngine`, `FlowStepDispatcher`, and every other execution-path class are untouched by this change.
- No existing flow row's `applicationSlug` is populated as part of this change — the column ships empty for all ~88 live flows.
