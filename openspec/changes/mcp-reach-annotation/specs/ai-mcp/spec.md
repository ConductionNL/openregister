## ADDED Requirements

### Requirement: A derived tool entry may declare its reach

A verb entry of the `x-openregister-mcp` dialect MAY carry a `reach` key whose value MUST be one of `self`, `user`, `instance` or `external` (`ToolReachLevels::ORDER`). The schema save MUST refuse any other value with the error code `mcp-bad-reach`. The tool derived from that verb MUST carry the declared value on its descriptor under `reach`, the key `ToolReachResolver` reads, and a verb that declares no `reach` MUST NOT get one invented. `reach` is advisory and MUST NOT gate invocation.

#### Scenario: a declared reach reaches the descriptor
- **GIVEN** a schema whose `x-openregister-mcp` tools declare `get` with `reach: user` and `update` with `reach: instance`
- **WHEN** the derived tools are listed
- **THEN** the `get` descriptor carries `reach: user` and the `update` descriptor `reach: instance`
- **AND** a `delete` verb that declares no reach carries no `reach` key

#### Scenario: an unknown reach is refused
- **WHEN** a schema is saved with a verb entry declaring `reach: everyone`
- **THEN** the save is refused with `mcp-bad-reach`
