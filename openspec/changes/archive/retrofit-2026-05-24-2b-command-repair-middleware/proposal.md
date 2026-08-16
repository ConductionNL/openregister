# Retrofit — command-repair-middleware (bundled)

Describes observed behavior of 18 methods across 4 files in three namespace-word directories (`lib/Command/`, `lib/Repair/`, `lib/Middleware/`) as 4 new REQs extending existing capabilities. Code already exists — this change retroactively specifies it.

Bundled because each domain in this batch is small (≤ 6 methods) and every cluster cleanly extends an existing capability rather than minting a new one. Generic `commands` / `repair-steps` / `middlewares` namespace-word capabilities are explicitly avoided.

## Affected code units

- `lib/Command/BackfillSystemOwnerCommand.php` — `__construct`, `configure`, `execute`, `resolveRegisters`, `resolveSchemas`, `backfillTable` (6 methods)
- `lib/Command/RematerialiseCalculationsCommand.php` — `__construct`, `configure`, `execute`, `withSelf`, `getCalculations` (5 methods)
- `lib/Repair/LogDanglingLinkedTypes.php` — `getName`, `run`, `loadSchemas`, `scan`, `extractLinkedTypes`, `safeStringAccessor` (6 methods)
- `lib/Middleware/TenantQuotaMiddleware.php` — `afterException` (1 method)

## REQ map

| REQ | Capability | Cluster | Methods |
|-----|------------|---------|---------|
| `auth-system#REQ-NNN` | auth-system | command | `BackfillSystemOwnerCommand` (6 methods) |
| `computed-fields#REQ-NNN` | computed-fields | command | `RematerialiseCalculationsCommand` (5 methods) |
| `linked-entity-types#REQ-NNN` | linked-entity-types | repair | `LogDanglingLinkedTypes` (6 methods) |
| `tenant-quotas#REQ-NNN` | tenant-quotas | middleware | `TenantQuotaMiddleware::afterException` (1 method) |

## Approach

- Bundle three small namespace-word clusters (`command`, `repair`, `middleware`) so each domain extends the capability whose state it touches, not a generic `commands` capability.
- `lib/Command/` — extend the capability whose state is being repaired/maintained (auth-system for `__system__` owner; computed-fields for `x-openregister-calculations` materialisation).
- `lib/Repair/` — extend the capability whose state is being scanned (`linked-entity-types` for dangling-type scan).
- `lib/Middleware/` — extend the capability whose runtime envelope it produces (`tenant-quotas` for the `afterException` JSON shape).
- Describe observed inputs, outputs, pre/postconditions, failure modes per method.
- Notes section surfaces observed-but-suspicious behavior (lazy SchemaMapper resolution, missing CalculationEvaluator on this branch, `LogDanglingLinkedTypes` pre-existing `@spec` pointing at a non-existent change).

## Dangling `@spec` note

`lib/Repair/LogDanglingLinkedTypes.php` (line 32) carries an existing `@spec openspec/changes/pluggable-integration-registry/tasks.md#task-11` annotation. That change directory does **not** exist on this branch (likely pending or archived under a different name). This retrofit retargets the annotations to `linked-entity-types` (the existing capability whose state is being scanned). The old pointer is left in place — its presence is harmless and lets a future scan re-link it if/when the pluggable-integration-registry change lands.

Source: `/tmp/or-scan/rspec-2b-command-repair-middleware.json` (batch JSON, 2026-05-24). See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
