## ADDED Requirements

### Requirement: A brokered credential carries a principal share list

A brokered credential SHALL support an optional `sharedWith[]` property listing
the principals it is shared with. Each entry SHALL declare a `type` of `user` or
`group`, an `id` (a Nextcloud user id or group id), and a `permission`. An
absent, empty, or malformed `sharedWith[]` SHALL grant nothing.

Group entries are RBAC *principals* only. A group SHALL NOT be consulted as a
tenant discriminator anywhere in share evaluation (ADR-002 Rule 1); the
organisation UUID remains the only tenant key.

Only the credential's owner SHALL be able to add, change, or remove entries. A
share recipient SHALL NOT be able to modify `sharedWith[]`, so a share can never
be used to widen itself or to re-share the credential onward.

`sharedWith[]` is metadata and SHALL NOT contain a secret in any property,
response body, export, audit row, or projection (ADR-004 Rule 1).

#### Scenario: Owner grants a share to a user

- **WHEN** the credential's owner adds `{ "type": "user", "id": "alice", "permission": "use" }`
- **THEN** the entry is persisted on the credential object
- **AND** no secret material appears in the response

#### Scenario: A share recipient cannot re-share or widen

- **WHEN** a user who appears in `sharedWith[]` attempts to add another principal to it
- **THEN** the request is refused
- **AND** `sharedWith[]` is unchanged

#### Scenario: A malformed entry grants nothing

- **WHEN** `sharedWith[]` contains an entry with no `id`, an unknown `type`, or a blank `id`
- **THEN** that entry admits no principal
- **AND** evaluation of the remaining entries is unaffected

#### Scenario: A group id is never a tenant key

- **WHEN** a share names a group whose members span more than one organisation
- **THEN** members outside the credential's organisation are still denied
- **AND** the tenant decision is taken from the organisation UUID, not from group membership

### Requirement: Sharing a credential grants use, never disclosure

A share SHALL grant the recipient the ability to cause the broker to make calls
with the credential. It SHALL NOT grant sight of the secret: no share-related
endpoint, projection, or error path SHALL return plaintext secret material to a
recipient, and the routed broker path SHALL continue to return only the upstream
response.

#### Scenario: Recipient drives a brokered call

- **WHEN** a share recipient triggers a brokered call for an app listed in `allowedApps`
- **THEN** the call is admitted and the secret is injected server-side from the vault
- **AND** the response contains the upstream result only, with no secret

#### Scenario: Recipient cannot read the secret

- **WHEN** a share recipient requests the credential object, its export, or its audit history
- **THEN** the secret is absent from every field returned
- **AND** the recipient sees only metadata

### Requirement: Revoking a share denies immediately

Removing a principal from `sharedWith[]` SHALL deny that principal's next
brokered call. Revocation SHALL NOT depend on cache expiry, session renewal, or
a background job.

#### Scenario: Revoked user is denied on the next call

- **WHEN** the owner removes `alice` from `sharedWith[]`
- **AND** `alice` triggers a brokered call against that credential
- **THEN** the call is denied with the standard fail-closed denial
- **AND** the denial is logged secret-free, identifying the credential by UUID only

### Requirement: Shares are discoverable by owner and recipient

The owner SHALL be able to list the principals a credential is shared with. A
user SHALL be able to list the credentials shared with them, directly or through
a group they belong to. Neither listing SHALL include secret material.

#### Scenario: Recipient lists credentials shared with them

- **WHEN** a user requests the credentials shared with them
- **THEN** credentials naming them directly and credentials naming a group they belong to are both returned
- **AND** each entry carries metadata only

#### Scenario: Unshared credential is not listed

- **WHEN** a user requests the credentials shared with them
- **THEN** a credential that names neither them nor any of their groups is absent from the result
