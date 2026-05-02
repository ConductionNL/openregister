---
status: in-progress
---

# Schema-Driven Read Coercion

## Purpose
Defines how OpenRegister coerces database column values to JSON-Schema-typed PHP values when reconstructing an `ObjectEntity` from a magic-table row. Establishes a single canonical converter (`SchemaTypeConverter`) that all read paths delegate to, eliminating the class of bugs where the schema declares one type but the API returns another — `boolean` properties arriving as `int 0`/`1` from MariaDB, or `string` properties whose values look like JSON literals being silently decoded back into the original primitive.

**OpenSpec changes**
- `fix-magic-table-type-coercion` (active) — introduces the `SchemaTypeConverter` service, refactors both `MagicStatisticsHandler::convertRowToObjectEntity` and `MagicSearchHandler::convertRowToObjectEntity` to delegate to it, and pins the contract with unit + integration tests.

## Requirements

### Requirement: Read coercion is governed by the active change
While this capability is in-progress, normative requirements MUST be sourced from the active change `fix-magic-table-type-coercion` under `openspec/changes/`. Implementers MUST treat this canonical spec as a placeholder until the change is archived and its delta is merged here.

#### Scenario: Implementer needs the canonical contract
- **WHEN** an implementer needs the normative behavior for schema-driven read coercion
- **THEN** they MUST consult the active change `fix-magic-table-type-coercion`
- **AND** they MUST NOT rely on this placeholder body for normative behavior

_Requirements for this capability are introduced by the active change above and will be merged here on archive._
