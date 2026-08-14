---
kind: code
---

# Proposal: register-scoped-schema-slug-resolution

## Summary

Schema slugs are resolved globally across every app on the instance, and the first matching row wins. Two apps that both own a schema called `agent`, `conversation`, `order`, or `task` therefore fight over the name, and the loser silently reads the winner's schema. `ObjectService::setSchema()` already fixes this for its own callers by trying `SchemaMapper::findBySlugInIds()` against the current register first — but that repair is local to one method, and every other resolution path still resolves globally. This change makes register-scoped resolution the rule rather than one method's workaround, and closes the paths that still guess.

## Motivation

The collision is not theoretical; it is producing wrong behaviour on the shared dev instance today.

`hermiq` and `openbuild` both own a schema with slug `agent` — hermiq's has 36 properties, openbuild's has 6. `GET /api/schemas/agent` resolves to openbuild's, because `SchemasController::show()` calls `$this->schemaMapper->find(id: $id)` with no register context and `find()` matches `LOWER(slug)` across the whole table. The consequence is already recorded in hermiq's manifest as a permanent workaround: hermiq's agent detail page is sized for the 9 fields it *declares* rather than the 1 field it *renders*, because `name` is the only property the two schemas share.

The same trap is about to be walked into deliberately. hermiq is standardising its vocabulary on "session" and needs a `session` schema — but `session` is already taken instance-wide by scholiq ("a scheduled occurrence of a Cohort meeting"). Rather than route around the collision with a defensive slug, this change removes the collision as a category.

OpenRegister's own code already knows this is the problem. The `setSchema()` docblock names it outright, and lists `conversation` as a canonical example of a generic slug that resolves to another app's schema.

## Affected Projects

- [ ] Project: `openregister` — schema slug resolution becomes register-scoped wherever a register context exists or can be derived

## Scope

### In Scope

- Audit every path that resolves a schema by slug and classify each as (a) already register-scoped, (b) has a register context it fails to use, or (c) genuinely has no register context available.
- Fix every path in class (b) to prefer `findBySlugInIds()` against the register's schema set before falling back to the global lookup — the pattern `ObjectService::setSchema()` already establishes.
- For class (c), decide and document per path whether it gains a register parameter or documents the ambiguity. `GET /api/schemas/{id}` is the known member: it takes no register and is the path that misresolves `agent` today.
- A regression test that FAILS on current `main`: two schemas sharing one slug in different registers, resolved with a register context, must return the right one.

### Out of Scope

- Renaming or deduplicating any existing colliding schema. The `agent` collision between hermiq and openbuild is fixed by correct resolution, not by renaming either schema.
- Enforcing global slug uniqueness. That would break existing installs and is the wrong model — a slug is meaningful within its register, which is the whole premise of this change.
- hermiq's own vocabulary change. This spec unblocks it; it does not perform it.
- The soft-delete read defect (`_includeDeleted` reaching only the count query) found in the same area on 2026-08-13. Related surface, separate fault, deliberately deferred to its own change.

## Approach

`SchemaMapper::findBySlugInIds(string $slug, array $schemaIds): ?Schema` already exists and already does the right thing — it matches `LOWER(slug)` restricted to a supplied set of schema ids, and returns null (a clean fall-through) when the set is empty or the slug is not in it. Nothing new needs inventing.

The work is to find every caller that has a register in hand and is not using it, and to route it through that method first. Where a public route has no register at all, the fix is a route-level decision rather than a mapper-level one — see design.md.

The audit is the substance of this change. A fix applied to the three paths someone happens to remember, on a codebase where the failure mode is silent, would leave exactly the class of bug this change exists to remove.

## New Dependencies

None.

## Impact

- `lib/Db/SchemaMapper.php` — no signature changes expected; `findBySlugInIds()` is already the primitive.
- `lib/Controller/SchemasController.php` — `show()` and any sibling that resolves a caller-supplied slug.
- Any service resolving a slug while holding a register.
- Behavioural: a caller that has been *reading another app's schema* starts reading its own. That is the fix, and it is also a behaviour change for anything that had adapted to the wrong answer — hermiq's agent-detail sizing workaround is the known example.

## Cross-Project Dependencies

This change is the head of a chain. `hermiq`'s `session-schema-declaration` and the three specs after it depend on it: hermiq takes the plain `session` slug on the strength of resolution being correct, rather than defensively picking a slug nobody else could want.

Because the dependent specs live in a **different repository**, Hydra's supervisor cannot enforce the ordering through `depends_on` (which resolves slugs to issue numbers within one repo). The ordering is therefore a human gate: this change must be merged and released before `session-schema-declaration` is applied. Called out again in that spec's proposal.

## Risks

### Risk 1: A consumer has adapted to the wrong schema
**Severity:** Medium — **Mitigation:** Correcting resolution changes what some callers receive. The known case (hermiq's agent detail) is a workaround that becomes unnecessary, not a regression. The audit must list each collision present on the instance so the blast radius is enumerated rather than discovered — `SELECT LOWER(slug), COUNT(*) FROM oc_openregister_schemas GROUP BY 1 HAVING COUNT(*) > 1` is the starting query.

### Risk 2: The audit misses a path
**Severity:** Medium — **Mitigation:** This is the failure mode the change exists to prevent, so the audit is a deliverable in its own right and the task list requires the enumeration to be written down, not just acted on. A path that resolves slugs and is deliberately left global must say why in a comment.

### Risk 3: Request-scoped caching hides the fix
**Severity:** Low — **Mitigation:** `SchemaMapper::find()` has a `findCache` keyed per request. If a cache key does not include the register context, a scoped lookup could return a globally-resolved entry cached earlier in the same request. Verify the key composition as part of the fix.

## Rollback Strategy

Revert the commit. The change adds no migration, no schema alteration, and no persisted state — resolution reverts to global, and any app that had adapted to the wrong answer is adapted to it again.

## Open Questions

- For `GET /api/schemas/{id}`: add an optional `?register=` and keep the current behaviour when absent, or resolve against every register the caller can see and 409 on a genuine ambiguity? The second is more correct and more disruptive. To be settled in design.md.
