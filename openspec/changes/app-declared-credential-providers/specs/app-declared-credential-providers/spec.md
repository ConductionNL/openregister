## ADDED Requirements

### Requirement: Declarations are discovered from shipped app config only

OpenRegister SHALL discover app credential-provider declarations by reading
`lib/Settings/credential-providers.json` from each installed app's own directory
(resolved via `IAppManager::getInstalledApps()` and `IAppManager::getAppPath()`),
and MUST NOT expose any API that creates, updates, or deletes a declaration. The
shipped catalogue at `openregister/lib/Settings/credential-providers.json`
remains runtime-immutable and is never written by this capability.

#### Scenario: An app's declaration file is discovered

- **WHEN** an installed, enabled app ships `lib/Settings/credential-providers.json`
- **THEN** OpenRegister reads that file and offers its entries for admission
- **AND** the file is read only, never written

#### Scenario: No write API exists for declarations

- **WHEN** any HTTP client attempts to create or modify a provider declaration
  through the OpenRegister API
- **THEN** no such route exists and the request cannot succeed

#### Scenario: A malformed declaration file contributes nothing

- **WHEN** an app's declaration file is unreadable, is not valid JSON, or fails
  declaration validation
- **THEN** OpenRegister logs a secret-free warning and admits no entry from that file
- **AND** provider resolution for that app's declared identifiers denies

### Requirement: Declared identifiers are namespaced and cannot shadow the reviewed catalogue

A declared provider's effective identifier SHALL be `<appId>:<localId>`, and
OpenRegister MUST resolve the shipped catalogue first, so a declaration can never
shadow, override, or replace a reviewed provider entry.

#### Scenario: A declaration cannot take a reviewed identifier

- **WHEN** an app declares an entry whose effective identifier would be `github`
  (no `<appId>:` prefix)
- **THEN** the declaration is rejected as invalid and admits nothing

#### Scenario: The base catalogue wins on resolution

- **WHEN** a credential names the provider `github`
- **THEN** the broker resolves the shipped catalogue entry
- **AND** no declaration layer is consulted for that identifier

#### Scenario: Two apps may declare the same local name

- **WHEN** app `alpha` and app `beta` each declare a local id `codeberg`
- **THEN** they resolve as `alpha:codeberg` and `beta:codeberg` independently

### Requirement: A narrowing declaration is admitted without administrator approval

A declaration carrying `extends: <base provider id>` SHALL be admitted on
discovery without an approval step if and only if it uses the base entry's
`baseUrl` and `authScheme` verbatim and every declared allow-rule is provably
narrower than or equal to a base allow-rule (same `method`, and a `pathPattern`
that matches no path the base rule does not already match). Such a declaration
grants no host and no path the reviewed catalogue does not already permit.

#### Scenario: A strict subset is admitted immediately

- **WHEN** an app declares `extends: github` with the single rule
  `GET /repos/*` and GitHub's base rules include `GET /repos/*`
- **THEN** the provider is usable without any administrator action
- **AND** it is listed in the admin view as auto-admitted (narrowing)

#### Scenario: An identical-to-base declaration is admitted

- **WHEN** an app declares `extends: gitlab` reproducing GitLab's rule set exactly
- **THEN** the provider is admitted, granting nothing beyond the base

### Requirement: A declaration that widens beyond its base is rejected, never escalated

A declaration carrying `extends` MUST be rejected as invalid when its host,
`authScheme`, or allow-rules exceed the base entry, and MUST NOT be converted
into a pending approval request. Anything not provably narrower than the base
SHALL be treated as widening.

#### Scenario: A wider path pattern is rejected

- **WHEN** an app declares `extends: github` with the rule `PUT /repos/*` while
  the base permits only `PUT /repos/*/contents/*`
- **THEN** the declaration is rejected as invalid
- **AND** no pending approval is created for it

#### Scenario: A different host under extends is rejected

- **WHEN** an app declares `extends: github` with a `baseUrl` other than the
  base entry's `baseUrl`
- **THEN** the declaration is rejected as invalid

#### Scenario: An unprovable pattern relationship is rejected

- **WHEN** a declared `pathPattern` cannot be shown to match only paths the base
  rule already matches
- **THEN** the declaration is rejected as invalid rather than admitted

### Requirement: A novel declaration is unusable until an administrator approves it

A declaration SHALL be recorded with status `pending` and MUST NOT resolve when
it names a host or a path the reviewed catalogue does not already permit. The broker
MUST deny every call and every secret resolution for a `pending`, `rejected`, or
`revoked` declaration, with a static 403 and a secret-free reason.

#### Scenario: A pending declaration denies

- **WHEN** an app declares a provider at a host absent from the reviewed
  catalogue and a credential is minted against it
- **THEN** every brokered call for that credential is denied with HTTP 403
- **AND** no outbound request is made

#### Scenario: An approved declaration resolves

- **WHEN** an administrator approves a pending declaration
- **THEN** the provider resolves and brokered calls proceed subject to the
  broker's existing guards

#### Scenario: A rejected declaration stays unusable

- **WHEN** an administrator rejects a pending declaration
- **THEN** the provider does not resolve and re-discovery does not re-open it as
  pending while the same digest is on record as rejected

### Requirement: Approval is pinned to a digest of the approved declaration

An approval record SHALL store a content digest computed over a canonical
serialisation of the individual declaration entry approved, and OpenRegister MUST
recompute that digest on every resolution. A digest mismatch MUST invalidate the
approval and return the provider to `pending`.

#### Scenario: Editing an approved declaration revokes its admission

- **WHEN** an approved app ships an app update that adds an allow-rule to that
  declaration
- **THEN** the recomputed digest no longer matches the approval
- **AND** the provider returns to `pending` and denies until re-approved

#### Scenario: An unrelated entry change does not disturb an approval

- **WHEN** an app adds a second, different declared provider to its file
- **THEN** the first provider's digest is unchanged and its approval stands

### Requirement: Approval decisions are recorded in the audit trail with the deciding user

Every approve, reject, and revoke decision SHALL be persisted as a
`credentialProviderApproval` object carrying `providerIdentifier`,
`declaringApp`, `declarationDigest`, `status`, `decidedBy`, `decidedAt`,
`decisionNote`, `baseUrl` and `allowRulesSnapshot`, so OpenRegister's immutable
hash-chained audit trail records who decided, when, and exactly what they saw.
Approval records MUST NOT be deleted when the declaring app is disabled or
uninstalled.

#### Scenario: An approval names its approver

- **WHEN** an administrator approves a declaration
- **THEN** an approval object is written with `decidedBy` set to that
  administrator's user id and `decidedAt` set to the decision time
- **AND** the audit trail carries the state transition

#### Scenario: The approved rule set is preserved

- **WHEN** the declaring app is later uninstalled
- **THEN** the approval record still shows the `baseUrl` and
  `allowRulesSnapshot` that were approved

#### Scenario: Only an administrator may decide

- **WHEN** a non-administrator invokes the approve, reject, or revoke endpoint
- **THEN** the request is refused and no approval object is written

### Requirement: A declared provider is usable only by the app that declared it

A credential minted against a declared provider SHALL have `allowedApps` forced
to exactly the declaring app, and OpenRegister MUST refuse any request that would
add another app to that credential's `allowedApps`.

#### Scenario: A second app cannot borrow the credential

- **WHEN** app `beta` calls the broker with a credential minted against
  `alpha:codeberg`
- **THEN** the existing allowed-app guard denies the call with HTTP 403

#### Scenario: allowedApps cannot be widened after mint

- **WHEN** the credential owner submits an update adding `beta` to the
  `allowedApps` of a credential minted against `alpha:codeberg`
- **THEN** the update is refused and `allowedApps` remains `["alpha"]`

### Requirement: A declaration MUST be a host-locked proxy entry

A declared provider entry SHALL carry a `baseUrl` and a non-empty `allowRules[]`,
and MUST NOT set `inject_only`. The reviewed catalogue remains the only source of
`inject_only` providers, so `resolveInjectable()` MUST return `null` for every
declared provider and `request()` remains the only path for a declared credential.

#### Scenario: An inject_only declaration is rejected

- **WHEN** an app declares an entry with `"inject_only": true`
- **THEN** the declaration is rejected as invalid and admits nothing

#### Scenario: A declaration without allow-rules is rejected

- **WHEN** an app declares an entry with no `baseUrl` or with an empty
  `allowRules[]`
- **THEN** the declaration is rejected as invalid

#### Scenario: resolveInjectable refuses a declared credential

- **WHEN** an app calls `resolveInjectable()` with a credential minted against an
  approved declared provider
- **THEN** `null` is returned and no secret leaves OpenRegister

#### Scenario: The reviewed generic inject_only entries are unchanged

- **WHEN** an app uses the reviewed `generic-bearer` provider
- **THEN** its behaviour is exactly as before this change

### Requirement: Declared providers fail closed on disable, uninstall, and revocation

Disabling or uninstalling the declaring app SHALL make its declared providers
unresolvable, and an administrator revocation MUST take effect on the next
resolution. In every case the broker MUST deny rather than fall back to any other
provider or make an unbounded call.

#### Scenario: Disabling the app denies its declared credentials

- **WHEN** the declaring app is disabled
- **THEN** brokered calls for credentials minted against its declared providers
  are denied with HTTP 403

#### Scenario: Revocation takes effect without a restart

- **WHEN** an administrator revokes an approved declaration
- **THEN** the next brokered call for that provider is denied

#### Scenario: Re-enabling requires a matching digest

- **WHEN** the declaring app is re-enabled with its declaration unchanged
- **THEN** the kept approval applies again and the provider resolves
- **AND** if the declaration changed, the provider is `pending` instead

### Requirement: The provider listing exposes origin and status but never allow-rules

`GET /api/credentials/providers` SHALL return, for every reviewed and every
discovered provider, only the `identifier`, `title`, `origin` (`catalogue` or the
declaring app id) and `status` (`admitted`, `pending`, `rejected`, `revoked`).
Allow-rules MUST NOT be returned by this endpoint.

#### Scenario: A picker can hide unusable providers

- **WHEN** a client requests the provider list
- **THEN** each entry carries its origin and status
- **AND** no `allowRules` or `baseUrl` field is present in the response

#### Scenario: The admin review view may show the full rule set

- **WHEN** an administrator opens the declared-providers admin view
- **THEN** the host and every method and path pattern are rendered for review

### Requirement: The approval schema ships with the credential-broker register migration

The `credentialProviderApproval` schema SHALL be added to
`lib/Settings/credential_broker_register.json` with a register version bump and
imported by the existing credential-broker Repair step. Seed rows MUST carry the
`example-` prefix, a `status` of `revoked`, and a digest that matches no real
declaration, so no seed row can ever admit a provider.

#### Scenario: A fresh install materialises the schema

- **WHEN** the credential-broker Repair step runs on a fresh install
- **THEN** the `credentialProviderApproval` schema exists in the credential-broker
  register with its seed rows

#### Scenario: Seed rows cannot admit anything

- **WHEN** an app declares a provider whose identifier matches a seed row
- **THEN** the seed row's `revoked` status and non-matching digest admit nothing
- **AND** the declaration is treated as `pending`

#### Scenario: An existing install upgrades without touching credentials

- **WHEN** the Repair step runs on an install that already has credentials
- **THEN** the schema is added and no existing credential or provider changes
