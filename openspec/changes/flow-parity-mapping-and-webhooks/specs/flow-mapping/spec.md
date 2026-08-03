# flow-mapping

## ADDED Requirements

### Requirement: One mapping engine

The system SHALL implement mapping evaluation exactly once, in OpenRegister.
OpenConnector SHALL NOT carry its own `MappingService`; its callers SHALL resolve
OpenRegister's. The consolidated service SHALL retain every capability either copy
had before consolidation, including `renderTemplateString` (previously only in
OpenConnector's copy) and mapping-cache invalidation (previously only in
OpenRegister's).

Two implementations of a transformation engine is a data-correctness problem
rather than duplication: the same mapping evaluated by two apps could produce two
results, and the caller has no way to know which one it got.

#### Scenario: The same mapping produces the same result whichever app evaluates it
- **WHEN** a stored mapping is evaluated through OpenConnector and through OpenRegister with identical input
- **THEN** both return byte-identical output, because both executed the same implementation.

#### Scenario: No second implementation remains
- **WHEN** the codebase is searched for a mapping service
- **THEN** exactly one `MappingService` class exists, owned by OpenRegister, and OpenConnector declares no mapping service of its own.

#### Scenario: A capability that existed in only one copy survives
- **WHEN** a caller renders a bare template string, and another caller invalidates the mapping cache
- **THEN** both succeed against the single consolidated service.

### Requirement: A flow can transform data mid-walk

The engine SHALL provide a node type `openregister.map` that evaluates a stored
mapping over the item list and replaces it with the result. A flow SHALL NOT need
to route data out to an endpoint rule and back in order to reshape it.

The node SHALL identify its mapping by id or slug. When the mapping cannot be
resolved, the step SHALL fail with a message naming the unresolved mapping, and
SHALL NOT pass the items through unchanged — a transformation that silently does
nothing is indistinguishable from one that ran.

#### Scenario: A mapping node reshapes the item list
- **WHEN** a flow runs an `openregister.map` step whose mapping renames `a` to `b`
- **THEN** the items leaving the step carry `b`, the items entering it carried `a`, and the step is recorded as completed.

#### Scenario: An unresolvable mapping fails the step
- **WHEN** an `openregister.map` step names a mapping that does not exist
- **THEN** the step is recorded as failed with the mapping's identifier in the message, and the items are NOT passed through unchanged.

#### Scenario: Mapping is available to every app's flows
- **WHEN** a flow owned by `openconnector` or `hermiq` uses `openregister.map`
- **THEN** the node resolves, because it is contributed to the shared catalogue rather than to one app's flows.
