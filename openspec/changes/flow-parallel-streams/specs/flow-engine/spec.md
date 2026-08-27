## REMOVED Requirements

### Requirement: SUSPENDING is a run-level act, so an EMPTY firing MUST NOT suspend @e2e exclude engine-internal suspend rule — covered by WaitNodeTest

**Reason**: Its premise is what this change removes. The requirement is built on
"`FlowSuspension` stops the WHOLE run and stores its marking; it is not scoped
to the branch that threw it", which is exactly the behaviour
`flow-parallel-streams` replaces with stream-scoped suspension. Leaving the
requirement in place would leave the spec asserting the opposite of the engine.

**Migration**: The rule it protects — an empty firing MUST NOT suspend — is
preserved verbatim, with all three of its scenarios, in the replacement
requirement "SUSPENDING is a STREAM-level act, and an EMPTY firing MUST NOT
suspend" below. No behaviour an author relies on changes for a flow with a
single branch, because a single-stream run IS the run.

## ADDED Requirements

### Requirement: SUSPENDING is a STREAM-level act, and an EMPTY firing MUST NOT suspend @e2e exclude engine-internal suspend rule — covered by WaitNodeTest

`FlowSuspension` parks the STREAM that raised it and stores the run's marking;
it does not park the branches that did not raise it. The run parks when every
stream has parked. A transition MAY fire with no items — a routing node sent
every item down another branch, or that branch had no work this pass — and a
node that waits MUST return those items unchanged rather than suspend.

The empty-firing rule survives the change of scope, for a sharper reason. An
empty branch that parks no longer stops the branch that DID carry an item, but
it still parks a stream that will never be woken by anything, and a stream in
that state holds open every join downstream of it: the join waits for a token
that a parked-forever branch will never deliver, and the run cannot reach a
terminal state. Where branches are PRIORITIES rather than alternatives — a
preferred branch evaluated first, falling through when it is empty — an empty
branch reaching a wait is the normal case, not an error.

Nothing is deferred by returning early. With no items there is nothing to
delay, and a later pass that DOES carry items reaches the node and suspends the
stream then.

#### Scenario: An empty branch does not pause the run
- **GIVEN** a flow whose routing node sends its only item to a collect branch, leaving a dispatch branch that also contains a wait
- **WHEN** the wait on the empty dispatch branch fires with no items
- **THEN** the run MUST NOT suspend
- **AND** the collect branch MUST advance in the same pass

#### Scenario: A wait carrying work still suspends
- **GIVEN** the same wait node and configuration
- **WHEN** it fires with one or more items on its first pass
- **THEN** it MUST suspend its own stream with the resolved `resumeAt`
- **AND** a sibling stream with work MUST keep advancing

#### Scenario: A resumed wait passes its items through
- **GIVEN** a run woken by the worker because `resumeAt` has passed
- **WHEN** the wait node runs a second time with `resuming` set
- **THEN** it MUST return its items unchanged rather than suspend again

#### Scenario: A one-branch flow is unchanged
- **GIVEN** a flow whose marking never holds more than one token
- **WHEN** any node suspends
- **THEN** the run MUST park exactly as it does today, with the same stored
  marking and the same `resumeAt`
