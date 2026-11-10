## ADDED Requirements

### Requirement: REQ-DIALECT-001 — The `x-openregister-mcp` schema dialect

OpenRegister MUST recognise a top-level `x-openregister-mcp` annotation key on
each schema, a member of the `x-openregister-*` dialect family (ADR-031), used to
declare — per schema, opt-in — which coarse CRUD MCP tools that schema exposes.
The key MUST be added to `Schema::ANNOTATION_VOCABULARY` so it is folded into the
schema's `configuration` on import rather than dropped as an unknown key. This
change defines and validates the declaration only; it MUST NOT emit any MCP tool
or alter any serving surface (that is the `or-mcp-derived-tool-provider` change).

The dialect object shape is:

- `enabled` (boolean, REQUIRED when the block is present) — the opt-in gate.
- `tools` (object, OPTIONAL) — keys MUST be a subset of the fixed verb set
  `{search, get, create, update, delete}`. Per-verb value is an object with
  optional `description` (string), `scope` (enum `read|create|update|delete`),
  and boolean MCP annotation hints `readOnlyHint` / `destructiveHint` /
  `idempotentHint`. The `search` verb additionally accepts `filters` (a list of
  strings, each naming an existing property on the schema).

The default posture MUST be OFF: a schema with no `x-openregister-mcp` block, or
with `enabled:false`, exposes no MCP tools.

#### Scenario: Dialect key is retained into configuration on import
- **GIVEN** a register seed schema carrying a top-level `x-openregister-mcp` block
- **WHEN** the schema is imported
- **THEN** `x-openregister-mcp` MUST be folded into the schema's `configuration`
- **AND** it MUST NOT appear in the dropped-unknown-key warning emitted by `SchemaMapper::logDroppedAnnotationKeys()`

#### Scenario: Default OFF — absent block exposes nothing
- **GIVEN** a schema with no `x-openregister-mcp` key
- **WHEN** the schema is saved
- **THEN** the save MUST succeed
- **AND** the schema's `configuration` MUST NOT contain an `x-openregister-mcp` entry

#### Scenario: enabled:false is a valid opt-out
- **GIVEN** a schema whose `x-openregister-mcp` block is `{ "enabled": false }`
- **WHEN** the schema is saved
- **THEN** the save MUST succeed
- **AND** the block MUST be stored verbatim in `configuration`

### Requirement: REQ-DIALECT-002 — Save-time validation of the dialect shape

OpenRegister MUST validate the `x-openregister-mcp` block at schema-save time via
a dedicated `McpAnnotationValidator`, invoked from `SchemaMapper::cleanObject()`
alongside the sibling dialect validators. A malformed block MUST fail the schema
save with a single aggregated, human-readable error message, consistent with the
existing `x-openregister-*` validators. The validator MUST check *types and
shape only* — it MUST NOT treat any MCP hint value as a security decision.

#### Scenario: enabled must be boolean
- **GIVEN** a schema whose `x-openregister-mcp` block sets `"enabled": "yes"`
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error naming the schema and the `enabled` type violation

#### Scenario: Unknown verb key is rejected
- **GIVEN** a `tools` object containing a key `list` (not in `{search,get,create,update,delete}`)
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error identifying the unrecognised verb `list`

#### Scenario: search filter must reference an existing property
- **GIVEN** a `search` verb whose `filters` lists `assignee`, but the schema has no `assignee` property
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error naming the unknown filter property `assignee`

#### Scenario: filters are permitted only on the search verb
- **GIVEN** a `create` verb config that includes a `filters` array
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error stating `filters` is valid on `search` only

#### Scenario: scope must be a known enum value
- **GIVEN** a verb config whose `scope` is `"admin"`
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error naming the invalid `scope` value

#### Scenario: MCP hints are validated by type, not trusted by value
- **GIVEN** a `delete` verb declaring `"destructiveHint": false`
- **WHEN** the schema is saved
- **THEN** the save MUST succeed (the boolean type is valid)
- **AND** the specification MUST record that the authoritative destructiveness gate at invoke time is OpenRegister RBAC, not this hint

#### Scenario: A well-formed full block saves and round-trips
- **GIVEN** a schema with `enabled:true` and all five verbs configured with valid `description`, `scope`, hints, and (for `search`) `filters` referencing real properties
- **WHEN** the schema is saved and re-read
- **THEN** the save MUST succeed
- **AND** the `x-openregister-mcp` block MUST be returned unchanged from `configuration`

### Requirement: REQ-DIALECT-003 — Coarse CRUD template, not per-endpoint

The dialect MUST express only a fixed, coarse five-verb CRUD template per schema
(`search`, `get`, `create`, `update`, `delete`) reusing the schema itself as the
tool input/output schema. It MUST NOT provide a mechanism to declare arbitrary
per-REST-endpoint tools. Non-CRUD, behaviour-specific tools are out of scope for
this declarative dialect and are the domain of the `#[McpTool]` service attribute
(`or-mcp-tool-attribute`).

#### Scenario: The verb set is closed
- **GIVEN** any `x-openregister-mcp.tools` object
- **WHEN** it is validated
- **THEN** only keys within `{search, get, create, update, delete}` MUST be accepted
- **AND** there MUST be no supported syntax for declaring a custom-named CRUD tool in this change
