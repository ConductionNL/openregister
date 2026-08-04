## ADDED Requirements

### Requirement: An object can be shared by link, as Files does

An owner MAY create a tokenised link to an object. The link SHALL be revocable and
MAY carry an expiry date and a password, using Nextcloud's own share mechanics
rather than a parallel implementation.

A link SHALL grant no more than the permission it was created with, and SHALL NOT
expose an object the sharer could not themselves access.

#### Scenario: A link grants access to its object

- **WHEN** an owner creates a link share on an object and the link is opened
- **THEN** the object is reachable through that link at the permission it carries

#### Scenario: A revoked link stops working immediately

- **WHEN** the owner revokes the link
- **THEN** opening it is refused

#### Scenario: An expired link stops working

- **WHEN** a link's expiry date has passed
- **THEN** opening it is refused

#### Scenario: A link cannot exceed the sharer's own access

- **WHEN** an owner creates a link with a permission wider than they hold themselves
- **THEN** the link is created at no more than the sharer's own permission, or refused

### Requirement: An object can be shared by email invitation

An owner MAY invite an address by email. The invitation SHALL be delivered through
Nextcloud's own mailer, and SHALL be revocable like any other share.

An invitation SHALL NOT disclose the object's contents in the message itself — the
recipient follows the invitation to reach the object, so revocation still works
after the mail has been sent.

#### Scenario: An invitation is sent and can be followed

- **WHEN** an owner invites an email address on an object
- **THEN** an invitation is delivered
- **AND** following it reaches the object at the granted permission

#### Scenario: An invitation is revocable after delivery

- **WHEN** the owner revokes an emailed invitation after it was delivered
- **THEN** following it is refused

#### Scenario: The message carries no object contents

- **WHEN** an invitation is delivered
- **THEN** the message body does not contain the object's data

### Requirement: A remote principal is one more principal

A federated principal on another instance SHALL be invitable on an object, resolved
through the existing cloud-federation provider. A federated grant SHALL yield the
same access decision as a local grant of the same permission, and SHALL NOT be a
second, parallel decision path.

#### Scenario: A federated grant admits the remote principal

- **WHEN** an object is shared with a principal on a remote instance
- **THEN** that principal is admitted at the granted permission
- **AND** the decision is taken by the same evaluator that decides a local grant

#### Scenario: Revoking a federated grant denies it

- **WHEN** a federated grant is revoked
- **THEN** the remote principal is denied
