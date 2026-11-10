## ADDED Requirements

### Requirement: A register-scoped schema-slug miss MUST NOT fall back to global resolution

When a caller supplies a register context and identifies a schema by slug, the
system MUST resolve that slug only among the schema ids the register carries. If
no schema in that register carries the slug, the system MUST throw
`SchemaNotInRegisterException` (extending `DoesNotExistException`, so existing
`404` mapping is preserved) rather than calling `SchemaMapper::find()`.

Global resolution remains the behaviour for callers that supply **no** register
context. The change is scoped precisely to callers that already named a register:
having named one, they MUST NOT be served a schema from outside it.

The exception message MUST name the register (id and slug), the requested slug,
and the count of same-slug schemas that exist elsewhere on the instance, and MUST
direct the operator to `occ openregister:registers:relink-schemas`. A message that
says only "schema not found" reproduces the defect's core harm — it is
indistinguishable from the slug genuinely not existing.

#### Scenario: A slug the register does not carry is refused, not resolved elsewhere

- **GIVEN** register `document` (id 6) carries schemas `[9173, 9174, 9177]`
- **AND** nine other schemas on the instance carry slug `anonymizationLink`, the lowest app-owned being id `5084`
- **WHEN** a caller resolves slug `signingRequest` against register `document`
- **THEN** the system MUST throw `SchemaNotInRegisterException`
- **AND** the message MUST name register `document` (id 6) and slug `signingRequest`
- **AND** `SchemaMapper::find()` MUST NOT be called

#### Scenario: A slug the register does carry resolves to the register's own schema

- **GIVEN** register `document` (id 6) carries schemas `[9173, 9174, 9177]`
- **AND** schema `9177` carries slug `anonymizationLink`
- **AND** schema `5084` also carries slug `anonymizationLink` and is the row global `find()` would return
- **WHEN** a caller resolves slug `anonymizationLink` against register `document`
- **THEN** the system MUST return schema `9177`, not `5084`

#### Scenario: A register with an empty schemas list refuses every slug

- **GIVEN** register `document` (id 6) has an empty `schemas` list
- **WHEN** a caller resolves slug `anonymizationLink` against register `document`
- **THEN** the system MUST throw `SchemaNotInRegisterException`
- **AND** the message MUST state that the register carries no schemas and name the repair command
- **AND** the system MUST NOT return schema `5084`

#### Scenario: A register-less caller keeps global resolution

- **GIVEN** a caller that supplies no register context
- **WHEN** it resolves slug `anonymizationLink`
- **THEN** the system MUST resolve through `SchemaMapper::find()` exactly as before
- **AND** MUST NOT throw `SchemaNotInRegisterException`

#### Scenario: A numeric id or uuid is unaffected by register scoping

- **GIVEN** a caller supplies register `document` and schema identifier `5084` as a numeric id
- **WHEN** the schema is resolved
- **THEN** the system MUST resolve `5084` directly, because scoping applies to slug resolution only
- **AND** MUST NOT throw merely because `5084` is absent from the register's list

### Requirement: Every register-scoped call site MUST enforce the refusal

The refusal MUST hold at every site that resolves a schema slug with a register in
hand, so that no path retains the fallback:

- `ObjectService::setSchema()`
- `ObjectService::searchObjectsBySlug()`
- `Service\Flow\Nodes\ObjectWriteNode`
- `Service\Flow\Nodes\ObjectReadNode`
- `Controller\SchemasController`

A single retained fallback re-opens the defect for whichever consumer happens to
use that path, so the requirement is enumerated by call site rather than stated
generally.

#### Scenario: The write path refuses rather than writing into a foreign schema

- **GIVEN** register `document` carries no schema with slug `generatedDocument`
- **AND** a schema `generatedDocument` owned by another application exists
- **WHEN** an object write targets register `document` and schema slug `generatedDocument`
- **THEN** the write MUST be refused with `SchemaNotInRegisterException`
- **AND** no object row MUST be created in any table

#### Scenario: The flow read node refuses rather than returning an empty set

- **GIVEN** a flow `ObjectReadNode` targets register `document` with slug `anonymizationLink`
- **AND** the register's `schemas` list is empty
- **WHEN** the node runs
- **THEN** the node MUST fail with `SchemaNotInRegisterException`
- **AND** MUST NOT return an empty result set, which would be indistinguishable from "no objects exist"

### Requirement: A lost register-schema linkage MUST be repairable from physical storage

Because a schema row carries no register column, a register's `schemas` list cannot
be reconstructed from the schema table. The system MUST reconstruct it instead from
the physical object tables, whose names encode the pairing as
`oc_openregister_table_<registerId>_<schemaId>`.

The system MUST expose `RegisterSchemaLinkageRepairService` that, for a given
register, returns every schema id having a physical table under that register, and
MUST distinguish tables that hold rows from tables that are empty — an empty table
is weaker evidence and the operator MUST be able to see the difference before
acting.

#### Scenario: Linkage is reconstructed for a register whose list was lost

- **GIVEN** register `6` has an empty `schemas` list
- **AND** tables `oc_openregister_table_6_9173`, `oc_openregister_table_6_9174` and `oc_openregister_table_6_9177` exist
- **WHEN** the repair service inspects register `6`
- **THEN** it MUST report schema ids `9173`, `9174` and `9177` as recoverable
- **AND** MUST report the live row count for each

#### Scenario: Repair reports nothing when there is no physical evidence

- **GIVEN** a register with an empty `schemas` list and no `oc_openregister_table_<id>_*` tables
- **WHEN** the repair service inspects it
- **THEN** it MUST report zero recoverable schema ids
- **AND** MUST NOT guess from slug similarity, application ownership, or any other heuristic

#### Scenario: Repair never removes an existing linkage

- **GIVEN** a register whose `schemas` list contains id `500`
- **AND** no physical table `oc_openregister_table_<id>_500` exists
- **WHEN** the repair runs
- **THEN** id `500` MUST be retained in the list
- **AND** the repair MUST only ever add ids, because a schema may be legitimately linked before its first object is written

### Requirement: The repair command MUST be dry-run by default

`occ openregister:registers:relink-schemas` MUST NOT write anything unless
`--write` is passed explicitly. Without it, the command MUST print each affected
register, the schema ids it would add, and each id's live row count, then exit
without mutation.

Writing to 17 registers as a side effect of a routine command is the same class of
surprise this change removes; the operator MUST see the change before it happens.

#### Scenario: Default invocation changes nothing

- **GIVEN** 17 registers have recoverable linkage
- **WHEN** `occ openregister:registers:relink-schemas` runs with no flags
- **THEN** it MUST print all 17 registers with their recoverable ids and row counts
- **AND** the `schemas` column of every register MUST be byte-identical afterwards

#### Scenario: Explicit write applies exactly what the dry run printed

- **GIVEN** a dry run reported register `6` gaining ids `9173`, `9174`, `9177`
- **WHEN** the command runs again with `--write`
- **THEN** register `6`'s `schemas` list MUST contain `9173`, `9174` and `9177`
- **AND** the command MUST report the number of registers actually changed

#### Scenario: A single register can be targeted

- **GIVEN** the operator wants to repair only register `6`
- **WHEN** `occ openregister:registers:relink-schemas --register=6 --write` runs
- **THEN** only register `6` MUST be modified
- **AND** the other 16 recoverable registers MUST be left unchanged
