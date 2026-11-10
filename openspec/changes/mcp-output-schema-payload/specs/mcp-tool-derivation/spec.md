## MODIFIED Requirements

### Requirement: A derived tool's `outputSchema` MUST describe the envelope, not the item

`SchemaDerivedToolProvider` MUST emit an `outputSchema` that describes the **shape of
the response**, and MUST NOT inline the underlying schema's property definitions.

- For a `search` verb: `{results: {type: array, items: {type: object}}, total: {type: integer}, hasMore: {type: boolean}}`.
- For a `get` verb: `{type: object}`.
- For write verbs: unchanged — no `outputSchema`.

The previous implementation inlined `$schema->getProperties()` in full, for both
verbs. Measured 2026-08-16 across 122 registered tools, that made `outputSchema`
**79.7% of the entire `tools/list` payload** — 335,580 of 433,198 bytes, roughly
84,000 of 108,000 tokens, on a payload re-sent every turn.

The item's properties are redundant to the caller: the model reads the actual result
when the tool returns. The envelope is not redundant — it tells the model a `search`
yields `{results, total, hasMore}` rather than a bare array, which is what stops it
guessing at the response shape.

Note the asymmetry this corrects. `buildInputSchema()` already narrows a `search`
verb's properties to the declared filters (REQ-DERIVED-004). The input path was
economical and the output path was not, which is why the imbalance reached 94% on
the worst tool (`shillinq.ARInvoice.search`: 36,293 B of outputSchema against
1,915 B of inputSchema).

#### Scenario: A search tool's outputSchema carries the envelope and no item properties

- **GIVEN** a schema with many declared properties and a `search` verb
- **WHEN** its tool descriptor is derived
- **THEN** `outputSchema.properties.results.items` MUST be `{"type": "object"}`
- **AND** `outputSchema.properties` MUST contain `results`, `total` and `hasMore`
- **AND** no property name from the underlying schema MUST appear anywhere in `outputSchema`

#### Scenario: A get tool's outputSchema is a bare object

- **GIVEN** a schema with many declared properties and a `get` verb
- **WHEN** its tool descriptor is derived
- **THEN** `outputSchema` MUST be `{"type": "object"}`
- **AND** no property name from the underlying schema MUST appear in it

#### Scenario: Write verbs still carry no outputSchema

- **GIVEN** a `create`, `update` or `delete` verb
- **WHEN** its tool descriptor is derived
- **THEN** no `outputSchema` key MUST be present

#### Scenario: inputSchema is untouched

- **GIVEN** a `search` verb declaring filters
- **WHEN** its tool descriptor is derived
- **THEN** `inputSchema` MUST still narrow to the declared filters exactly as before
- **AND** its content MUST be unchanged by this requirement

### Requirement: The derived tool payload MUST be measured, not assumed

A test MUST assert the total serialised size of a derived tool set against a
declared ceiling, so that a future change which re-inlines schema properties fails
loudly rather than silently doubling every agent's prompt.

A payload regression is invisible from every other angle: no test fails, no gate
fires, nothing errors. It shows up as agents becoming slower and dumber, which is
attributed to the model.

#### Scenario: A representative schema's derived tools stay within budget

- **GIVEN** a schema with at least 20 declared properties and both `search` and `get` verbs
- **WHEN** its tool descriptors are derived and serialised
- **THEN** the combined size MUST be under 8,000 bytes
- **AND** the test failure message MUST state the measured size and the ceiling

#### Scenario: Re-inlining the item properties fails the test

- **GIVEN** an implementation that inlines the schema's properties into `outputSchema`
- **WHEN** the budget test runs
- **THEN** it MUST fail, because that is the regression this requirement exists to catch
