# Tasks: register-scoped-schema-slug-resolution

## 1. Establish the failure before fixing it

- [ ] 1.1 Write the regression test FIRST and watch it fail: two schemas sharing one slug in two registers, resolved with a register in context, must return that register's schema. A test written after the fix that passes immediately proves only that nothing crashed.
- [ ] 1.2 Exercise both resolution orders within a SINGLE request (global-first-then-scoped, and scoped-first-then-global) so a `findCache` key that omits the register is caught. One order alone passes against a broken key.
- [ ] 1.3 Record the instance's actual collisions: `SELECT LOWER(slug), COUNT(*) FROM oc_openregister_schemas GROUP BY 1 HAVING COUNT(*) > 1`. This is the blast radius; paste it into design.md.

Acceptance criteria
- The new test fails on unmodified `main`, citing the wrong schema id in its failure message.
- The collision list is written down, not just observed.

## 2. Audit every slug-resolving path

- [ ] 2.1 Enumerate every call site that resolves a schema by slug (`SchemaMapper::find()` callers plus any direct slug query) and record each in a table in design.md.
- [ ] 2.2 Classify each: (a) already register-scoped, (b) holds a register but does not use it, (c) genuinely has no register available.
- [ ] 2.3 For every (c), add an inline comment stating WHY it stays global. A path left global without a stated reason is indistinguishable from one that was missed — which is the bug this change exists to remove.

Acceptance criteria
- The table lists every path with its disposition; no path is unclassified.
- `ObjectService::setSchema()` appears as the (a) reference implementation.

## 3. Fix the class-(b) paths

- [ ] 3.1 Route each class-(b) caller through `SchemaMapper::findBySlugInIds()` against the register's schema set, falling back to `find()` — the pattern `ObjectService::setSchema()` already establishes. Do NOT change `find()` itself or its signature.
- [ ] 3.2 Verify the `findCache` key composition includes the register context on the scoped path (or that the scoped path bypasses the cache). Task 1.2 is the check.
- [ ] 3.3 Confirm behaviour is unchanged for numeric ids and uuids — `findBySlugInIds()` returns null for those and must fall through cleanly.

Acceptance criteria
- The task 1.1 test passes.
- `GET /api/objects/hermiq/agent` returns hermiq's 36-property schema, not openbuild's 6-property one.

## 4. `GET /api/schemas/{id}` — optional register scope

- [ ] 4.1 Accept an optional `?register=` on `SchemasController::show()` and resolve register-scoped when present (design.md Decision 3, option (a)).
- [ ] 4.2 Keep current behaviour when absent — this is a foundation repo and 18 apps consume it; do not turn a working request into a 409 in this change.
- [ ] 4.3 When the slug is ambiguous and no register was supplied, log at debug level with the candidate schema ids, so the next investigation starts from evidence.

Acceptance criteria
- `GET /api/schemas/agent?register=hermiq` returns hermiq's schema; without the parameter the response is byte-identical to today's.
- The ambiguity log line names every candidate id.

## 5. Quality and hand-off

- [ ] 5.1 `composer check:strict` clean (PHPCS, PHPMD, Psalm, PHPStan); fix any pre-existing issues touched.
- [ ] 5.2 Note in the PR that hermiq's `agent-core` widget sizing workaround can be revisited once this ships — it exists solely because of this bug.
- [ ] 5.3 Tell the hermiq chain this is merged AND released. `session-schema-declaration` lives in another repository, so Hydra's supervisor cannot gate it on this issue; the ordering is a human gate.

Acceptance criteria
- Strict quality gates pass.
- The dependent hermiq spec is not started before this is released.
