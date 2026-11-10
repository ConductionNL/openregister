# Design: register-scoped-schema-slug-resolution

## Context

`SchemaMapper::find()` resolves an identifier through an `orX(id, uuid, slug)` and returns the first matching row. For a numeric id or a uuid that is unambiguous. For a slug it is not: slugs are unique *within a register*, never across the instance, so `LOWER(slug) = 'agent'` can match several rows and the winner is whichever the database hands back first.

Two mitigations already exist, and the gap between them is the bug:

1. `SchemaMapper::findBySlugInIds(string $slug, array $schemaIds): ?Schema` — matches a slug restricted to a supplied id set. Returns `null` when the set is empty or the slug is not in it, which makes it safe to try first and fall through.
2. `ObjectService::setSchema()` — calls (1) against `$this->currentRegister->getSchemas()` before falling back to the global `find()`. Its docblock states the problem plainly and names `conversation` as an example.

So the primitive is built and one caller uses it. Every other slug-resolving path still calls `find()` directly.

Measured on the dev instance, 2026-08-13:

| Observation | Value |
|---|---|
| Schemas with slug `agent` | hermiq's (36 properties) and openbuild's (6 properties) |
| `GET /api/schemas/agent` resolves to | openbuild's |
| Schemas with slug `session` | scholiq's (id 1286) |
| Only property hermiq and openbuild's `agent` share | `name` |

The consequence is already load-bearing: hermiq's agent detail page carries a comment explaining that the `agent-core` widget is sized for the 9 fields it declares rather than the 1 it renders, "because that is an OpenRegister slug-resolution bug, not a layout one".

## Goals / Non-Goals

**Goals:**

- A slug resolved with a register in context returns *that register's* schema, on every path, not just through `ObjectService`.
- The set of paths that resolve slugs is written down, so the next person does not have to rediscover it.
- A path that genuinely cannot be register-scoped says so in a comment, rather than being silently left global.
- A regression test that fails before the fix and passes after.

**Non-Goals:**

- Global slug uniqueness. Slugs are register-scoped by design; enforcing global uniqueness would break every install that has two apps with an `order` schema, and would be solving the wrong problem.
- Renaming any colliding schema.
- Changing `find()`'s signature or its behaviour for ids and uuids.

## Decisions

### Decision 1: Fix the callers, not `find()`

`find()` stays as it is. It has no register context and inventing one — an ambient "current register" on the mapper — would make resolution depend on hidden state and be worse than the bug.

Instead each caller that holds a register routes through `findBySlugInIds()` first and falls back to `find()`. This is exactly the shape `setSchema()` already uses, so the fix is a known-good pattern applied consistently rather than a new mechanism.

### Decision 2: The audit is a deliverable, not a step

The failure mode here is silent — a wrong schema resolves successfully and returns plausible data. A fix applied to the paths someone remembers would leave the same class of bug in the paths they did not, and would look complete.

So the change produces an enumerated list of every slug-resolving path with a disposition for each: scoped / fixed / deliberately global. The task list requires that list to exist as a written artifact.

### Decision 3: `GET /api/schemas/{id}` takes an optional `?register=`

This is the open question from the proposal. Two candidates:

- **(a) Optional `?register=`; unchanged when absent.** Backwards-compatible. A caller that knows which register it means gets the right schema; a caller that does not gets today's first-row-wins behaviour.
- **(b) Resolve across the caller's visible registers and `409` on genuine ambiguity.** More correct — it never silently returns the wrong schema — but it turns a currently-working request into an error for any existing consumer relying on the accidental winner.

**Chosen: (a).** Option (b) converts a silent wrong answer into a loud failure, which is usually the right trade, but it does it for *every* existing consumer of a colliding slug at once, in a foundation repository that 18 apps depend on. The proportionate step is to make the correct answer *reachable* now and to consider (b) separately once the audit reveals how many consumers are actually affected.

The ambiguity that remains when `?register=` is absent must be logged at debug level with the candidate ids, so the next investigation starts from evidence instead of a screenshot.

### Decision 4: Verify the `findCache` key

`SchemaMapper::find()` caches per request. If the cache key is the raw identifier, then within one request a global resolution can populate `agent → openbuild` and a later register-scoped call could read it back. The fix must confirm the scoped path either bypasses that cache or keys it with the register — and the regression test must exercise both orders within a single request, because a test that only ever resolves scoped-first would pass against a broken key.

### Declarative-vs-imperative decision (ADR-031)

Not applicable. This change introduces no lifecycle, aggregation, derived field, notification, relation, or widget behaviour — it corrects an existing lookup inside the data-access layer. No `x-openregister-*` declaration is appropriate and no new service class is added.

### Seed Data (ADR-001)

No new schema is introduced, so no seed objects are required. The regression test does construct two throwaway schemas sharing one slug in two registers; those are test fixtures created and torn down by the test, not seed data, and must not be added to `_registers.json`.

## Risks / Trade-offs

- **A consumer adapted to the wrong answer.** Correcting resolution changes what some callers receive. The known case (hermiq's agent-detail sizing) is a workaround that becomes unnecessary. The audit must enumerate the collisions actually present so the blast radius is known rather than discovered; start from `SELECT LOWER(slug), COUNT(*) FROM oc_openregister_schemas GROUP BY 1 HAVING COUNT(*) > 1`.
- **Decision 3 leaves a known-imperfect path.** `GET /api/schemas/{id}` without `?register=` still guesses. That is a deliberate, documented deferral, not an oversight — and it is now logged rather than silent.
- **This is the foundation repository.** Every app resolves schemas. The regression test failing before the fix is the minimum bar for believing the change does anything; a test written after the fix, that passes immediately, would prove only that it does not crash.
