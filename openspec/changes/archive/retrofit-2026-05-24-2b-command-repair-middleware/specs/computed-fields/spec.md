---
retrofit_extensions:
  - REQ-001
---

# Computed Fields — Retrofit Delta

Adds 1 REQ extending `computed-fields` with the operational OCC command that re-evaluates every materialised calculation declared by a schema (via the `x-openregister-calculations` configuration block) and rewrites the persisted value. The command is used after a schema's calculation expression changes, so existing objects reflect the new shape without waiting for the next user-driven save.

This delta extends `computed-fields` because both features express derived property values via schema-attached expressions evaluated server-side. The save-time persistence pattern documented in the main spec mirrors what this command re-runs in bulk.

## Requirements

### REQ-001: The system SHALL provide an OCC command that re-evaluates and persists every materialised calculation declared by a (register, schema) pair

`occ openregister:rematerialise-calculations <register> <schema> [--dry-run]` iterates every object in the (register, schema) magic table, re-evaluates each materialised calculation declared in the schema's `configuration.x-openregister-calculations` map, and persists the new value when the result differs from the stored value. A calculation is "materialised" when its declaration in the calculations map satisfies `materialise === true`; non-materialised entries are skipped.

The command is implemented in `OCA\OpenRegister\Command\RematerialiseCalculationsCommand`. It accepts two required arguments (`register` and `schema`, each resolvable by slug, uuid, or id) and one optional flag (`--dry-run`). On invocation it:
1. Resolves register and schema via `RegisterMapper::find()` / `SchemaMapper::find()` with `_multitenancy: false`; lookup failures emit `<error>` to stderr and return `Command::FAILURE`.
2. Reads the calculations map via `getCalculations(schema)` (returns `$schema->getConfiguration()['x-openregister-calculations']` or null). Null or empty map → writes `<comment>Schema declares no x-openregister-calculations — nothing to do.</comment>` and exits `Command::SUCCESS`.
3. Filters the map to materialised names. Empty list → writes `<comment>No materialised calculations declared — nothing to do.</comment>` and exits `Command::SUCCESS`.
4. Pulls all entities (capped at 100,000 per run) via `MagicMapper::findAllInRegisterSchemaTable(register, schema, limit: 100000)`.
5. For each entity, builds an evaluation payload by injecting the synthetic `@self` metadata block (`id`, `uuid`, `register`, `schema`, `owner`, `created`, `updated`) into the object data via `withSelf()`. Created/updated are formatted as `DateTimeInterface::ATOM` strings (null when absent).
6. For each materialised calculation: calls `CalculationEvaluator::evaluate(payload, expression)`. `DateTimeInterface` results are normalised to ATOM strings. The stored value at `$data[$name]` is compared against the new value using `!==`; a difference marks the entity as `changed`. Per-calculation evaluation errors are counted in `$failed`, written as `<error>! <name> on <uuid>: <message></error>`, and do not abort the iteration.
7. When `--dry-run` is absent and the entity is changed, the new data is persisted via `ObjectService::saveObject(object: $data, register: $entity->getRegister(), schema: $entity->getSchema(), uuid: $entity->getUuid())`. Save failures are written as `<error>save failed on <uuid>: <message></error>` and counted in `$failed`.

The final line reports `<info>Touched <touched>, unchanged <unchanged>, failed <failed></info>`. Exit status is `Command::FAILURE` when `$failed > 0`, else `Command::SUCCESS`.

#### Scenario: Re-materialise a changed expression across the schema

- **GIVEN** a schema with `x-openregister-calculations: { totalIncl: { materialise: true, expression: "<expr-v2>" } }` whose expression was just changed from v1 to v2
- **AND** 500 objects exist in the register × schema table, all carrying the v1-evaluated value of `totalIncl`
- **WHEN** the admin runs `occ openregister:rematerialise-calculations <register> <schema>`
- **THEN** the command loads all 500 entities, evaluates v2 with the `@self`-augmented payload, and saves each entity via `ObjectService::saveObject()` where the new value differs from the stored value
- **AND** the final line reports `Touched 500, unchanged 0, failed 0`
- **AND** the command exits `Command::SUCCESS` (0)

#### Scenario: Schema declares no calculations

- **GIVEN** a schema with no `x-openregister-calculations` key in its configuration (or with an empty map)
- **WHEN** the admin runs `occ openregister:rematerialise-calculations <register> <schema>`
- **THEN** `getCalculations()` returns null or `[]`
- **AND** the command writes `Schema declares no x-openregister-calculations — nothing to do.` and exits `Command::SUCCESS`

#### Scenario: Schema declares only non-materialised calculations

- **GIVEN** a schema where every calculation entry has `materialise: false` (or omits the flag)
- **WHEN** the admin runs the command
- **THEN** the materialised-names filter produces an empty list
- **AND** the command writes `No materialised calculations declared — nothing to do.` and exits `Command::SUCCESS`

#### Scenario: Dry-run reports diffs without persisting

- **GIVEN** 100 entities where v2 evaluation produces a value different from the stored v1 value
- **WHEN** the admin runs `occ openregister:rematerialise-calculations <register> <schema> --dry-run`
- **THEN** every entity is evaluated and counted as `touched`
- **AND** `ObjectService::saveObject()` is NEVER called
- **AND** the final line reports `Touched 100, unchanged 0, failed 0`

#### Scenario: DateTime calculation result is normalised to ATOM

- **GIVEN** a materialised calculation whose expression returns a `\DateTime` instance (e.g. `vervaldatum = ingangsdatum + 1 year`)
- **WHEN** the command evaluates it for a given entity
- **THEN** the `\DateTime` result is converted via `->format(DateTimeInterface::ATOM)` before comparison and storage
- **AND** the persisted value is the ATOM string, not the object

#### Scenario: Evaluation failure on a single entity does not abort the batch

- **GIVEN** a calculation whose expression throws on entity `<uuid-bad>` (e.g. division by zero from a null field)
- **WHEN** the command evaluates that entity
- **THEN** `! <name> on <uuid-bad>: <message>` is written to stderr
- **AND** `$failed` is incremented
- **AND** the loop continues with the next entity
- **AND** the final command exits `Command::FAILURE` (because `$failed > 0`)

#### Scenario: `@self` metadata is injected into the evaluation payload

- **GIVEN** an entity with `getUuid() = '<uuid>'`, `getCreated()` returning a DateTime, `getOwner() = 'jan'`
- **WHEN** `withSelf(data, entity)` is called
- **THEN** the returned payload contains `@self.id`, `@self.uuid`, `@self.register`, `@self.schema`, `@self.owner`, and ATOM-formatted `@self.created` / `@self.updated`
- **AND** expressions referencing `@self.owner` or `@self.created` evaluate against those values

### Notes

- **100k entity cap is observed, not enforced upstream.** The command passes `limit: 100000` to `MagicMapper::findAllInRegisterSchemaTable()`. Larger tables would silently skip the tail. Not a bug to fix here, but a real operational constraint surfaced by the spec.
- **`CalculationEvaluator` is imported but absent on this branch.** The command's `use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;` import points at a class not present in this worktree's `lib/Service/Calculation/`. The command must therefore be unreachable on this branch (autoload failure on construction). Spec describes the observed code shape; verifying that the evaluator class lands in a future merge is left to the next scan.
- **`saveObject()` re-runs the full save pipeline** — including ValidationHandler, hooks, computed-fields recomputation, and SOLR indexing. Re-materialising 100k objects in a single run is therefore expensive; operators should plan windows.
