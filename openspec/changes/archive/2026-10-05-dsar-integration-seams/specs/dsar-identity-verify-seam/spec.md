## ADDED Requirements

### Requirement: OpenRegister defines an identity-verify provider interface
OpenRegister SHALL define an `IdentityVerifyProvider` interface that verifies a data-subject's identity for a DSAR case and returns exactly one of `verified`, `failed`, or `needs-more`.
The interface MUST be a narrow PHP contract (identify itself by a stable provider `id`, and verify-for-case → a status result), NOT a hard-coded verification service or an inline check in the case engine. It is the legitimate ADR-019 / ADR-031 registry-seam exception; leaf apps (e.g. pipelinq NL→BSN/BRP) implement it later and OUT of scope here.

#### Scenario: The interface exposes a stable id and a verify result
- **WHEN** a provider implementing `IdentityVerifyProvider` is inspected
- **THEN** it MUST expose a stable provider `id` and a verify-for-case operation
- **AND** that operation MUST return exactly one of `verified`, `failed`, or `needs-more`

#### Scenario: No hard-coded verification lives in the engine
- **WHEN** the case engine needs to verify a data-subject's identity
- **THEN** it MUST call an `IdentityVerifyProvider` obtained from the registry
- **AND** no jurisdiction-specific or hard-coded identity check MUST be embedded in the engine

@e2e An administrator inspecting the identity-verify seam sees the provider contract exposing a stable id and a three-state verify result, with no built-in NL-specific check.

### Requirement: Leaf apps register identity providers into an identity-verify registry
OpenRegister SHALL ship an `IdentityVerifyRegistry`, a shared per-request service into which any leaf app registers an `IdentityVerifyProvider` from its own bootstrap, following the existing OR `IntegrationRegistry`/`ObjectSourceRegistry` first-wins collision policy.
The registry MUST collect providers keyed by id, MUST accept the first registration of an id and log a warning on a duplicate id (first-wins), and MUST expose the registered providers so resolution can select one. Registration MUST mirror the existing OR registry bootstrap in `lib/AppInfo/Application.php` (ADR-019).

#### Scenario: A leaf provider registers and is discoverable
- **WHEN** a leaf app registers an `IdentityVerifyProvider` with a unique id from its bootstrap
- **THEN** the registry MUST hold that provider under its id
- **AND** resolution MUST be able to select it by that id

#### Scenario: Duplicate provider id is first-wins
- **WHEN** two providers register the same id
- **THEN** the first registration MUST win and the second MUST be rejected with a logged warning
- **AND** the registry MUST NOT silently replace the first provider

@e2e A steward registering a second identity provider under an existing id sees the first-wins collision logged and the original provider retained.

### Requirement: The identity provider resolves from the active policy pack selector
OpenRegister SHALL resolve the active identity provider for a case from the active `dsarPolicyPack`'s `identityVerifyProvider` selector via the registry, never from a hardcoded provider reference.
Resolution MUST look up the provider registered under the selector id and return it, so the case engine calls identity-verify through the registry using the jurisdiction's chosen provider. Changing the pack selector MUST change which provider verifies, with no PHP code change.

#### Scenario: Verification uses the pack-selected provider
- **WHEN** a case's active pack sets `identityVerifyProvider` to a registered provider id
- **THEN** the engine MUST resolve that provider through the registry and use it to verify identity
- **AND** changing the selector to another registered provider MUST switch verification without a code change

#### Scenario: Engine never calls a hardcoded provider
- **WHEN** the case engine reaches the `verifying` state
- **THEN** it MUST obtain the identity provider from the registry via the pack selector
- **AND** it MUST NOT reference any provider by a hardcoded class or id

@e2e A steward points a jurisdiction pack's identityVerifyProvider at a different registered provider and observes a case verify through the newly selected provider without a redeploy.

### Requirement: An unbound or unknown identity seam fails closed
OpenRegister SHALL resolve an unset or unknown `identityVerifyProvider` selector to an OR-shipped fail-closed default provider that returns unverified (`failed`/`needs-more`), never auto-verified and never null.
Resolution MUST NOT return null and MUST NOT treat "verification unavailable" as "identity verified" — a missing provider MUST NOT silently skip the identity check (ADR-005 no fail-open, CWE-863). The OR default provider MUST register at bootstrap so a fresh install always resolves a provider, and the default pack MUST select it.

#### Scenario: Unset selector resolves to the fail-closed default
- **WHEN** the active pack leaves `identityVerifyProvider` unset
- **THEN** resolution MUST return the OR fail-closed default provider
- **AND** verifying a case with it MUST yield an unverified result (`failed` or `needs-more`), never `verified`

#### Scenario: Unknown provider id does not skip the check
- **WHEN** the pack names an `identityVerifyProvider` id that is not registered
- **THEN** resolution MUST fall back to the fail-closed default rather than returning null
- **AND** the identity check MUST NOT be silently skipped or treated as satisfied

@e2e On an install with no leaf identity provider bound, a steward advances a case to verifying and sees it fail closed (unverified) rather than auto-passing.
