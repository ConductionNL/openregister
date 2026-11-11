## ADDED Requirements

### Requirement: OpenRegister defines a regulator-escalate provider interface
OpenRegister SHALL define a `RegulatorEscalateProvider` interface that escalates or dossiers a DSAR case to a supervisory authority and returns the escalation outcome.
The interface MUST be a narrow PHP contract (identify itself by a stable provider `id`, and escalate-for-case → an escalation result carrying a regulator reference and a status), NOT a hard-coded escalation service or an inline call in the case engine. It is the legitimate ADR-019 / ADR-031 registry-seam exception; leaf apps (e.g. pipelinq NL→AP) implement it later and OUT of scope here.

#### Scenario: The interface exposes a stable id and an escalate result
- **WHEN** a provider implementing `RegulatorEscalateProvider` is inspected
- **THEN** it MUST expose a stable provider `id` and an escalate-for-case operation
- **AND** that operation MUST return an outcome carrying a regulator reference and a status

#### Scenario: No hard-coded escalation lives in the engine
- **WHEN** the case engine needs to escalate a case to a supervisory authority
- **THEN** it MUST call a `RegulatorEscalateProvider` obtained from the registry
- **AND** no jurisdiction-specific or hard-coded escalation target MUST be embedded in the engine

@e2e An administrator inspecting the regulator-escalate seam sees the provider contract exposing a stable id and an escalate result, with no built-in AP-specific target.

### Requirement: Leaf apps register regulator providers into a regulator-escalate registry
OpenRegister SHALL ship a `RegulatorEscalateRegistry`, a shared per-request service into which any leaf app registers a `RegulatorEscalateProvider` from its own bootstrap, following the existing OR `IntegrationRegistry`/`ObjectSourceRegistry` first-wins collision policy.
The registry MUST collect providers keyed by id, MUST accept the first registration of an id and log a warning on a duplicate id (first-wins), and MUST expose the registered providers so resolution can select one. Registration MUST mirror the existing OR registry bootstrap in `lib/AppInfo/Application.php` (ADR-019).

#### Scenario: A leaf provider registers and is discoverable
- **WHEN** a leaf app registers a `RegulatorEscalateProvider` with a unique id from its bootstrap
- **THEN** the registry MUST hold that provider under its id
- **AND** resolution MUST be able to select it by that id

#### Scenario: Duplicate provider id is first-wins
- **WHEN** two providers register the same id
- **THEN** the first registration MUST win and the second MUST be rejected with a logged warning
- **AND** the registry MUST NOT silently replace the first provider

@e2e A steward registering a second regulator provider under an existing id sees the first-wins collision logged and the original provider retained.

### Requirement: The regulator provider resolves from the active policy pack selector
OpenRegister SHALL resolve the active regulator provider for a case from the active `dsarPolicyPack`'s `regulatorEscalateProvider` selector via the registry, never from a hardcoded provider reference.
Resolution MUST look up the provider registered under the selector id and return it, so the case engine escalates through the registry using the jurisdiction's chosen provider. Changing the pack selector MUST change which provider escalates, with no PHP code change.

#### Scenario: Escalation uses the pack-selected provider
- **WHEN** a case's active pack sets `regulatorEscalateProvider` to a registered provider id
- **THEN** the engine MUST resolve that provider through the registry and use it to escalate
- **AND** changing the selector to another registered provider MUST switch escalation without a code change

#### Scenario: Engine never calls a hardcoded provider
- **WHEN** the case engine reaches a denial/escalation point
- **THEN** it MUST obtain the regulator provider from the registry via the pack selector
- **AND** it MUST NOT reference any provider by a hardcoded class or id

@e2e A steward points a jurisdiction pack's regulatorEscalateProvider at a different registered provider and observes a case escalate through the newly selected provider without a redeploy.

### Requirement: An unbound or unknown regulator seam fails closed
OpenRegister SHALL resolve an unset or unknown `regulatorEscalateProvider` selector to an OR-shipped fail-closed default provider that refuses the escalation, never a silent success and never null.
Resolution MUST NOT return null and MUST NOT treat "escalation unavailable" as "escalation done" — a missing provider MUST NOT silently skip the escalation (ADR-005 no fail-open, CWE-863). The OR default provider MUST register at bootstrap so a fresh install always resolves a provider, and the default pack MUST select it.

#### Scenario: Unset selector resolves to the fail-closed default
- **WHEN** the active pack leaves `regulatorEscalateProvider` unset
- **THEN** resolution MUST return the OR fail-closed default provider
- **AND** escalating a case with it MUST refuse (report escalation not performed), never report success

#### Scenario: Unknown provider id does not skip the escalation
- **WHEN** the pack names a `regulatorEscalateProvider` id that is not registered
- **THEN** resolution MUST fall back to the fail-closed default rather than returning null
- **AND** the escalation MUST NOT be silently skipped or recorded as done

@e2e On an install with no leaf regulator provider bound, a steward escalates a case and sees it fail closed (escalation refused) rather than silently reported as done.
