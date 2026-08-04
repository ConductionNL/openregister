## ADDED Requirements

<!--
  These are ADDED, not MODIFIED: the canonical openspec/specs/credential-broker/spec.md
  carries no requirement text for provider resolution or mint validation today
  (that behaviour is described in ADR-004 Rule 3 and in unarchived changes), so
  there is no existing requirement block to copy forward. These requirements state
  the amended behaviour in full.
-->

### Requirement: Provider resolution consults the shipped catalogue first, then an admitted declaration

`CredentialBrokerService` SHALL resolve a credential's provider by looking up the
shipped, runtime-immutable catalogue first and, only when that yields nothing, an
app declaration that is currently admitted. Any other outcome MUST deny. A
pending, rejected, revoked, invalid, or digest-drifted declaration MUST NOT
resolve, and the shipped catalogue MUST remain unwritable at runtime.

#### Scenario: A reviewed provider resolves from the catalogue

- **WHEN** a credential names `mollie`
- **THEN** the shipped catalogue entry is used and no declaration is consulted

#### Scenario: An admitted declaration resolves

- **WHEN** a credential names `alpha:codeberg` and that declaration is admitted
- **THEN** the declared entry is used

#### Scenario: An unknown or non-admitted provider denies

- **WHEN** a credential names a provider that is neither in the catalogue nor an
  admitted declaration
- **THEN** the broker denies with HTTP 403 and a secret-free reason

### Requirement: A declared provider is guarded by the same four guards as a reviewed provider

A resolved declaration SHALL yield the same provider entry shape as a catalogue
entry, so the owner/organisation guard, the allowed-app guard, the allow-rule
guard, and the host-lock guard MUST run unchanged and in the same order for a
declared provider. No guard may be skipped, reordered, or weakened for a declared
provider.

#### Scenario: The host-lock applies to a declared baseUrl

- **WHEN** a caller supplies a path that would resolve to a host other than the
  declared `baseUrl` host
- **THEN** the host-lock guard denies with HTTP 403

#### Scenario: The allow-rule guard applies to declared rules

- **WHEN** a caller requests a method and path not matched by any declared
  allow-rule
- **THEN** the allow-rule guard denies with HTTP 403

#### Scenario: Denials remain secret-free

- **WHEN** any guard denies a declared-provider call
- **THEN** the logged reason carries only the credential UUID and a static reason
  and never any part of the secret

### Requirement: Minting against a declared provider validates admission and forces app scope

`CredentialController::create()` SHALL accept a namespaced declared provider
identifier only when that declaration is currently admitted, and MUST set the new
credential's `allowedApps` to exactly the declaring app. Minting against a
pending, rejected, revoked, or unknown declaration MUST be refused.

#### Scenario: Minting against a pending declaration is refused

- **WHEN** a user creates a credential for a declared provider awaiting approval
- **THEN** the request is refused and no vault secret is written

#### Scenario: Minting forces the declaring app as the only allowed app

- **WHEN** a user creates a credential for the admitted provider `alpha:codeberg`
  and supplies `allowedApps: ["alpha", "beta"]`
- **THEN** the stored credential has `allowedApps` equal to `["alpha"]`

#### Scenario: Reviewed providers keep their existing mint behaviour

- **WHEN** a user creates a credential for `github`
- **THEN** provider validation and `allowedApps` handling are exactly as before
  this change
