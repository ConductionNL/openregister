# Design — `x-openregister-archival` Annotation Support

## Context

`x-openregister-archival` is silently stripped on schema import (`Schema::ANNOTATION_VOCABULARY` does not include it; the strip happens at `validateConfigurationArray()` line 1697). The openconnector descriptor declares retention rules on its 4 log schemas but no part of the runtime honours them.

A heavier sibling spec `archival-destruction-workflow` exists (NEN 15489 / Archiefwet — per-object `archiefactiedatum`, multi-step approval, destruction lists, legal hold). That spec is the **records-management** surface and stores its state on the object instance (`_retention` column / `getRetention()`). The annotation surface this change adds is the **declarative schema-level** layer: a developer puts ISO-8601 durations + condition rules on the *schema* and OR enforces them automatically — without any per-object archiefnominatie ceremony.

The two specs intentionally share the same `_retention` column but populate it at different lifecycle moments:
- archival-destruction-workflow writes to `_retention` from `ArchivalService::setRetention()` (operator-driven).
- add-archival-annotation-support reads from the annotation + the row's columns to compute `_retention.effectiveRetention` at evaluation time (auto-derived).

There is no write conflict because the annotation-driven evaluator only **reads** to populate the `_retention` block on the JSON response; persistence of the column remains owned by the records-management surface.

## Decisions

### D1: Two new vocabulary keys at once (`x-openregister-archival` + `x-openregister-seed`).

`x-openregister-seed` is in the issue scope ("intended to be honored elsewhere") because the openconnector descriptor declares it alongside archival. Even if no listener exists yet, putting it in the vocabulary now stops the strip + warning from firing on every install. When seed support lands (separate spec) the round-trip is already in place.

### D2: Validator in its own file, not embedded in `SchemaMapper`.

Mirrors the existing pattern (`LifecycleAnnotationValidator`, `AggregationAnnotationValidator`, `WidgetAnnotationValidator`, `NotificationAnnotationValidator`, `CalculationAnnotationValidator`). Keeps `SchemaMapper` focused on persistence; lets the validator be unit-tested in isolation.

### D3: Use `\DateInterval` for ISO-8601 duration parsing.

PHP's `\DateInterval` already accepts the full ISO-8601 duration grammar (`P30D`, `PT1H`, `P1Y6M`, …). No third-party dependency, no hand-rolled regex. Throws `\Exception` on malformed input which the validator catches and translates to a structured error.

### D4: Condition language deliberately tiny (`<field> <op> <literal>`).

The openconnector use case is `statusCode < 400` / `statusCode >= 400`. Spec calls out keeping the evaluator minimal. Real expression engines (Symfony ExpressionLanguage, hoa/ruler) exist but pull in dependencies, sandboxing, and a much larger surface area. The minimal evaluator is ~80 LOC + a parser test suite and covers every condition the fleet currently needs. Future expansion is mechanical (add an `AND` / `OR` combinator) if a real need shows up.

### D5: Immutability gate uses a private `_retentionSweep` flag, NOT a separate `deleteObjectInternal()` method.

`ObjectService::deleteObject()` already has `_rbac` / `_multitenancy` private signature flags. Adding `_retentionSweep` keeps the gate next to the existing checks and avoids a parallel mutation API surface that would have to stay in sync. The cron is the only caller that sets it `true`; it is reachable only via PHP DI (no controller / route exposes it) so the gate cannot be bypassed by an HTTP client.

### D6: Cron is per-schema iteration with native SQL, NOT a SELECT across all magic tables.

Magic tables are per-`(register, schema)` (table prefix + register-id + schema-id). The schemas with `x-openregister-archival` set are a small subset of the total. Iterating only those schemas and running a focused `SELECT … FROM <table>` per schema is both simpler and the documented Postgres-pattern OR already uses elsewhere (`AggregationRunner::tryNativeAggregation()` per the `aggregations-backend-native` change). A union over all magic tables would require schema introspection at cron-run time and a much wider lock surface.

### D7: `_retention` on read is computed, not stored.

The annotation-driven `effectiveRetention` / `matchedRule` / `expiresAt` reflect the **annotation as it stands now**. If the annotation changes (e.g. `P30D` → `P7D`) every row's effective retention recomputes on next read. Storing the computed value in `_retention` would freeze it at write time and create a divergence the next time the annotation moves. The records-management surface still uses the column for *its* per-object metadata; the annotation surface adds a sibling computed block at JSON-serialize time, so the two never write the same key.

### D8: ISO-8601 duration is the only retention DSL.

The records-management surface uses `archiefactiedatum` (absolute date). This change uses ISO-8601 duration (relative to row's `_created`). The two are intentionally divergent because:
- Records management: "this contract must be kept until 2055-03-14".
- Annotation: "any call_log row older than 30 days is gone".

The first is human-curated, the second is mechanical. Mixing them in one DSL would force every record to either commit to an absolute date (impossible at schema-declaration time) or to a duration (impossible for per-object legal review). They stay separate.

## Risks

- **R1: Performance at scale.** The cron iterates every row in every archival schema once per hour. For openconnector at the openconnector fleet's expected log volume (~1M call_log rows / day) the per-schema sweep should stay under a few seconds with the magic-table's existing `_created` btree index. If it doesn't, a follow-up adds a partial index on `expires_at` derived from a materialised view; out of scope for v1.

- **R2: Condition evaluation cost.** The condition grammar is tiny; evaluating ~1 instruction per row stays in micro-seconds. Cron-batch impact is dominated by the SELECT + DELETE round-trip, not by evaluation.

- **R3: Drift between annotation-driven and records-management retention.** Both surfaces will write to `_retention` for different rows. The contract: annotation surface only ever writes `_retention.annotation = { … }` (sub-key); records-management owns `_retention.archiefactiedatum`, `_retention.archiefnominatie`, … . jsonSerialize merges them without overwriting. If the annotation evaluator's result fits under a single `_retention.annotation` namespace, we never collide with the records-management writes.

- **R4: Cron deletes user data.** The sweep deletes rows past their retention. Two safeguards: (a) `_retentionSweep` flag must be true (only the cron sets it); (b) the standard `DeleteObject` handler fires audit trails so every deletion is logged. Recovery via the soft-delete path is a future ADR (out of scope v1).

## Alternatives considered

- **A1: Re-use `appendOnly` flag instead of a new exception.** Rejected: `appendOnly` is the stricter / general-purpose immutability switch (no UPDATE either). Archival schemas can legally be updated (e.g. enrich a call_log row with a follow-up annotation); only DELETE is forbidden. They need separate exceptions.

- **A2: Compute `_retention` at write time and store it.** Rejected per D7. The annotation can change after the row is written; recomputing on read is the correct invariant.

- **A3: Single combined `archival-annotation-support` + records-management spec.** Rejected: the records-management spec is much heavier (multi-step approval, legal hold, destruction certificates), already shipped, and intentionally a different policy. Combining would force a redesign of the (working) records-management surface to fit the annotation-driven model.
