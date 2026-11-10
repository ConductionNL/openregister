# flow-engine

## ADDED Requirements

### Requirement: Trigger matching speaks slugs on both sides

The `(event, register, schema)` triple is matched as SLUGS. The trigger index
SHALL store the register slug and schema slug for every derived row, whatever
identifier the trigger node's config holds — an imported declaration's slug
and a builder select's numeric id MUST produce the same row. The fired
subject's register and schema SHALL be resolved to slugs before matching, so
an object event carrying numeric ids matches a trigger declared with slugs
and vice versa.

An identifier that resolves to no register or schema SHALL pass through
unchanged rather than being dropped or blanked: an unresolvable identifier
that silently became an empty value would unsubscribe the flow, which is the
silence this rule exists to end.

The resolution SHALL live in exactly one implementation, used by the listener
and by the index writer alike — a second answer to "what does this triple look
like" would diverge on exactly the flows nobody looks at.

#### Scenario: An imported flow fires on an object event

- **GIVEN** a flow imported from `x-openregister-flows` whose trigger node
  names its register and schema by slug
- **WHEN** an object of that register and schema is created, the event
  carrying the object's numeric ids
- **THEN** the flow MUST match and a run MUST be queued
- @e2e exclude engine-internal matching seam — covered by
  `FlowTriggerIdSlugMatchTest` end to end and `FlowTriggerListenerTest` at the
  listener

#### Scenario: A trigger node holding numeric ids indexes as slugs

- **GIVEN** a flow whose published trigger node config holds a numeric
  register id and schema id
- **WHEN** its trigger rows are derived
- **THEN** the written rows MUST hold the register and schema SLUGS
- @e2e exclude engine-internal index write — covered by
  `FlowTriggerIndexSlugNormalisationTest`

#### Scenario: The registered repair rewrites pre-fix rows

- **GIVEN** an instance whose trigger index still holds rows in the id
  vocabulary, written before this rule
- **WHEN** the `BackfillFlowTriggerIndex` repair step runs on upgrade
- **THEN** every flow's rows MUST be rebuilt through the normalising writer,
  so already-imported flows start firing without being re-saved
- @e2e exclude an upgrade-time repair — covered by
  `FlowTriggerIndexSlugNormalisationTest` through the same rebuild path

#### Scenario: An unresolvable identifier is matched as-is

- **WHEN** a subject or trigger names a register that no longer resolves
- **THEN** the value MUST pass through unchanged rather than becoming empty
- @e2e exclude engine-internal resolver behaviour — covered by
  `FlowTriggerSlugsTest`
