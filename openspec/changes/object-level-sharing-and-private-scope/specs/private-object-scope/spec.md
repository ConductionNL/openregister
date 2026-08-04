## ADDED Requirements

### Requirement: `private` is an RBAC principal that admits only the owner and administrators

An object MAY declare a `private` scope. When it does, access SHALL be granted to
its owner, to Nextcloud administrators, and to principals explicitly invited on
that object — and to nobody else. The schema's group rules SHALL be suppressed for
that object; suppressing them is the purpose of the scope.

`private` SHALL be opt-in. An object that does not declare it SHALL be decided
exactly as it is today, so no existing object changes visibility when this ships.

`private` SHALL only ever narrow access. It SHALL NOT admit a principal the
schema would refuse, so it can never be used to widen.

#### Scenario: A private object is hidden from the organisation

- **WHEN** an object declares the `private` scope and a member of its organisation who is neither its owner nor invited requests it
- **THEN** the request is denied
- **AND** the object is absent from that user's list results

#### Scenario: The owner always reaches their own private object

- **WHEN** the owner of a private object requests it
- **THEN** it is returned, whatever the schema's group rules say

#### Scenario: An administrator always reaches a private object

- **WHEN** a Nextcloud administrator requests a private object
- **THEN** it is returned

#### Scenario: An absent scope changes nothing

- **WHEN** an object does not declare the `private` scope
- **THEN** the schema's authorization rules decide exactly as they did before this capability existed

#### Scenario: `private` cannot widen access

- **WHEN** a schema's rules would refuse a user an action, and a private object of that schema invites them for it
- **THEN** the request is still denied

### Requirement: Owner and administrator admits are evaluated first and unconditionally

The owner and administrator admit paths SHALL be evaluated BEFORE any scope or
rule evaluation, and SHALL NOT be conditional on the scope, the schema block, or a
match clause. An owner SHALL NOT be able to lock themselves out of their own
object by making it private.

#### Scenario: A private object with no invitations is still reachable by its owner

- **WHEN** an owner makes an object private and invites nobody
- **THEN** the owner can still read, update and delete it

#### Scenario: A malformed scope value does not lock the owner out

- **WHEN** an object's scope value is unrecognised
- **THEN** the owner and administrators are still admitted
- **AND** no other principal is admitted

### Requirement: The `private` principal is honoured identically on every enforcement path

`private` SHALL be resolved by the single-object verdict, the relation-path
verdict, and BOTH list-emitting paths, yielding identical verdicts for the same
object and caller. A principal honoured on one path and not another is an
access-control defect: it presents as an empty page when over-filtering, and as a
leak when under-filtering.

#### Scenario: The same verdict on find and on list

- **WHEN** a caller who is not admitted requests a private object directly and then lists objects of its schema
- **THEN** the direct request is denied
- **AND** the object is absent from the list result

#### Scenario: An invited principal sees it on both paths

- **WHEN** an invited principal requests a private object directly and then lists objects of its schema
- **THEN** the direct request succeeds
- **AND** the object is present in the list result
