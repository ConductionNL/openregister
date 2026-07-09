---
status: done
---

# Semantic Object Handoff (Engine)

## Purpose

The OR-owned semantic-object-handoff engine (ADR-051): cross-app workflows hand objects over via canonical semantic kinds, declared on the source schema through the `x-openregister-handoff` dialect, bound on the implementing schema through `handoffContract`, executed by `HandoffService` on top of `SemanticTypeResolver` with RBAC, provenance relations, immutable audit, ADR-041 events, and hide/queue degradation. Mirrors the hydra contract spec `hydra/openspec/changes/semantic-object-handoff/specs/semantic-object-handoff/spec.md` 1:1 so the OR main spec and the cross-app contract cannot drift.

**OpenSpec changes**: [semantic-object-handoff-engine](../../changes/archive/2026-07-06-semantic-object-handoff-engine/) _(archived 2026-07-06)_

## Requirements

### Requirement: `x-openregister-handoff` declarative dialect

A source schema SHALL declare its handoffs via an `x-openregister-handoff`
array inside its `x-openregister` block (ADR-031 dialect family). Each entry
SHALL declare an `id`, a `targetSemanticType` (canonical kind URI), a
`trigger` (`manual` or `lifecycle:<state>`), a `mapping` object whose keys are
target **kind-contract field names** and whose values are one of the mapping
expression kinds `from` (copy), `const`, `template` ({{prop}} interpolation,
HTML-escaped), `semanticRef` (carry an ADR-048 semantic reference across as a
reference), or `provenance` (engine-filled source pointer), an optional
`whenUnavailable` (`hide` default, or `queue`), and an optional
`onSuccess.set` map applied to the source object after a successful handoff.

#### Scenario: Valid handoff declaration is accepted

@e2e exclude schema-save validator behaviour — covered by PHPUnit tests/Unit/Service/Handoff/HandoffAnnotationValidatorTest.php

- **WHEN** a schema is saved with a handoff entry declaring `targetSemanticType: "https://openregister.app/ns#Case"`, a mapping using only the five expression kinds, and `onSuccess.set: { "status": "handed-off" }`
- **THEN** schema-save validation SHALL accept the schema and the handoff becomes available on that schema's objects

#### Scenario: Unknown mapping expression kind is rejected

@e2e exclude schema-save validator behaviour — covered by PHPUnit tests/Unit/Service/Handoff/HandoffAnnotationValidatorTest.php

- **WHEN** a schema is saved with a mapping value using an expression kind outside `[from, const, template, semanticRef, provenance]`
- **THEN** schema-save validation SHALL reject the schema with a `handoff-bad-mapping-expression` error naming the offending field

#### Scenario: Target kind URI is malformed

@e2e exclude schema-save validator behaviour — covered by PHPUnit tests/Unit/Service/Handoff/HandoffAnnotationValidatorTest.php

- **WHEN** a handoff entry declares a `targetSemanticType` that is not an absolute URI
- **THEN** schema-save validation SHALL reject the schema with a `handoff-bad-target-type` error

#### Scenario: `onSuccess.set` names a property missing from the source schema

@e2e exclude schema-save validator behaviour — covered by PHPUnit tests/Unit/Service/Handoff/HandoffAnnotationValidatorTest.php

- **WHEN** a handoff entry declares `onSuccess.set` for a property the source schema does not define
- **THEN** schema-save validation SHALL reject the schema with a `handoff-bad-success-update` error

### Requirement: Kind contract binding on the implementing schema

A schema implementing a handoff kind SHALL declare a `handoffContract`
binding block mapping each kind-contract field name to one of its own
properties. The kind-implementation claim itself stays on the existing
`implements[]` / `jsonld.type` / `x-schema-org` markers (ADR-048). The engine SHALL translate contract fields to
implementing-schema properties exclusively through this binding, so the
emitting schema never names a concrete target property.

#### Scenario: Implementer binds all mandatory contract fields

@e2e exclude schema-save validator behaviour — covered by PHPUnit tests/Unit/Service/Handoff/HandoffContractBindingValidatorTest.php

- **WHEN** a schema declaring `implements: ["https://openregister.app/ns#Case"]` is saved with a `handoffContract` binding covering every mandatory `ns#Case` contract field
- **THEN** schema-save validation SHALL accept it and the schema becomes a resolvable handoff provider for `ns#Case`

#### Scenario: Implementer omits a mandatory contract field

@e2e exclude schema-save validator behaviour — covered by PHPUnit tests/Unit/Service/Handoff/HandoffContractBindingValidatorTest.php

- **WHEN** a schema declares `implements` for a kind but its `handoffContract` binding omits a mandatory field of that kind's contract
- **THEN** schema-save validation SHALL reject the schema with a `handoff-contract-incomplete` error listing the missing fields

### Requirement: HandoffService executes conversions on top of SemanticTypeResolver

OpenRegister SHALL provide a `HandoffService` that executes a declared
handoff by (1) resolving the implementing schema for the target kind via
`SemanticTypeResolver` (null-safe, RBAC/org-scoped, deterministic tie-break,
disabled apps excluded), (2) creating the target object by applying the
mapping through the target's `handoffContract` binding, (3) writing a typed
`handoff` provenance relation source↔target in both directions, (4) writing
one immutable audit-trail entry on each object (actor, kind, handoff id,
resolved target schema), and (5) applying the `onSuccess.set` update to the
source through the normal lifecycle-aware write path. Steps 2–4 SHALL be
atomic: a handoff either fully happens or leaves no partial state.

#### Scenario: Successful request-to-case handoff

- **WHEN** a user with write access to a pipelinq `request` object and create access on the resolved `ns#Case` provider triggers the `request-to-case` handoff
- **THEN** the engine creates one target object populated per the mapping, links `handoff` provenance in both directions, writes an audit entry on both objects, and sets the source's `status` to `handed-off`

#### Scenario: Target create fails mid-handoff

@e2e exclude transactional rollback is not UI-observable — covered by PHPUnit HandoffServiceTest::testAtomicRollbackOnTargetCreateFailure

- **WHEN** the target object create is rejected (e.g. target schema validation failure)
- **THEN** no provenance relation, no audit entry, and no source status update SHALL be persisted, and the caller receives the validation error

#### Scenario: Caller lacks create permission on the target schema

@e2e exclude RBAC refusal covered by PHPUnit HandoffServiceTest::testCreateRefusalHappensBeforeAnyWrite + the Newman auth cases

- **WHEN** a user triggers a handoff but OR RBAC denies create on the resolved implementing schema
- **THEN** the engine SHALL refuse with a permission error and SHALL NOT escalate privileges to complete the conversion

#### Scenario: Semantic references are carried, not copied

- **WHEN** a mapping entry declares `semanticRef` for a source property holding an ADR-048 semantic reference (e.g. `request.client`)
- **THEN** the target object's contract field holds a semantic reference to the same referenced object's UUID, and no referenced-object data is duplicated into the target

### Requirement: Graceful degradation when no provider implements the kind

A handoff whose target kind resolves to no installed schema SHALL degrade
without error (null from `SemanticTypeResolver`): with `whenUnavailable: hide`
(default) the action is not offered and API execution returns a
provider-unavailable response; with `whenUnavailable: queue` the request is
parked and executed automatically once a provider schema is installed. The
source object SHALL remain fully functional standalone in both modes.

#### Scenario: No provider installed, hide mode

- **WHEN** no installed schema implements `ns#Case` and a `request` schema declares the handoff with `whenUnavailable: hide`
- **THEN** the handoff action is absent from the object's UI surface, direct API execution returns a `handoff-provider-unavailable` response (not a 5xx), and the request object continues to work standalone

#### Scenario: No provider installed, queue mode

- **WHEN** a handoff with `whenUnavailable: queue` is triggered while no provider is installed, and a provider app is installed later
- **THEN** the parked handoff SHALL be executed against the newly resolved provider, with the audit entry recording the deferred execution

#### Scenario: Provider app installed but disabled

- **WHEN** the only schema implementing the target kind belongs to a disabled app
- **THEN** the resolver treats it as no provider (per shipped `SemanticTypeResolver` behaviour) and the degradation scenarios above apply

### Requirement: ADR-041 event emission on handoff execution

The engine SHALL dispatch a typed handoff-executed event (ADR-041 shape:
provenance — sourceApp, source register/schema/id, subject label,
correlationId — plus the target kind, resolved target register/schema/id, and
handoff id) after each successful handoff, so the consuming app can run
intake logic in its own DI context. Terminal-state feedback from the consumer
SHALL reuse the existing ADR-041 conclusion-event pattern, gated to
provenance-carrying handoffs only. The integration registry SHALL NOT be used
as the transport (ADR-041 / gate-27).

#### Scenario: Consumer intake listener runs after handoff

@e2e exclude requires a consuming app's listener — verified in the adoption changes (procest semantic-case-intake); event fields covered by PHPUnit HandoffServiceTest

- **WHEN** a `ns#Case` handoff completes and the providing app registers a listener for the handoff-executed event
- **THEN** the listener receives full provenance + the created object's identifiers and can apply intake logic (e.g. case numbering) through its own services

#### Scenario: No listener registered

@e2e exclude listener-absence is not UI-observable — event dispatch + success without subscribers covered by PHPUnit HandoffServiceTest

- **WHEN** a handoff completes for a kind whose providing app registers no listener
- **THEN** the handoff still succeeds (object + provenance + audit); the event simply has no subscriber

### Requirement: Handoff REST surface

OpenRegister SHALL expose REST endpoints to (1) execute a declared handoff on
a source object and (2) report handoff availability for an object — each
declared handoff with whether its target kind currently resolves to an
installed provider (reusing the resolver's reason semantics so the UI can
render the ADR-048-style "provider not installed" explanation). Both
endpoints SHALL be registered in `appinfo/routes.php` with explicit auth
posture and per-object authorization (ADR-005, ADR-016, ADR-029).

#### Scenario: Availability endpoint with provider present

- **WHEN** the availability endpoint is called for a `request` object while procest's `case` schema implements `ns#Case`
- **THEN** the response lists `request-to-case` as available, naming the resolved provider schema

#### Scenario: Availability endpoint without provider

- **WHEN** the availability endpoint is called while no schema implements the target kind
- **THEN** the response lists the handoff as unavailable with a machine-readable reason code suitable for the "provider not installed" UI copy

#### Scenario: Execute endpoint on an object whose schema declares no handoffs

@e2e exclude API error-shape case — covered by the Newman collection tests/newman/openregister-handoff.postman_collection.json + PHPUnit HandoffServiceTest::testExecuteNotDeclared

- **WHEN** the execute endpoint is called with a handoff id on an object whose schema declares no `x-openregister-handoff` entries
- **THEN** the endpoint returns a `handoff-not-declared` client error and performs no writes

@e2e A user opens a source object whose schema declares a handoff, sees the handoff action offered when a provider schema is installed, executes it, and finds the created target object linked via the "handed off to / originated from" provenance relation on both sides with the source status updated; with the provider app disabled, the same action shows the explained provider-not-installed state and direct API execution returns the machine-readable unavailable response instead of a 5xx.
