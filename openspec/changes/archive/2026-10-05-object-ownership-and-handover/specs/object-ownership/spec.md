# object-ownership

## ADDED Requirements

### Requirement: A record has one named owner, and the owner is admitted unconditionally (REQ-OWN-001)

Every record SHALL carry a named owner. The owner SHALL be admitted to read and
edit their own record whatever the schema's group rules say, so an owner cannot be
locked out of a record they are answerable for. A record declared `private` SHALL
answer to its owner, its owning group, administrators and invited principals only.

#### Scenario: the owner edits their own private record

- **GIVEN** a record declaring the `private` scope, owned by `alice`
- **WHEN** `alice` updates it
- **THEN** the update succeeds
- @e2e exclude {owner admit path, covered by unit tests on the one resolver every path calls}

#### Scenario: a stranger cannot edit a private record

- **GIVEN** a record declaring the `private` scope, owned by `alice`
- **WHEN** `mallory`, who is neither owner nor administrator nor invited, updates it
- **THEN** the update is refused
- @e2e exclude {owner admit path, covered by unit tests on the one resolver every path calls}

### Requirement: Ownership is derived from the authenticated actor and is never claimable (REQ-OWN-002)

The owner SHALL be derived from the authenticated actor at creation. A write that
names an owner other than the acting user SHALL be REFUSED with HTTP 403, and the
refusal SHALL name the endpoint that does change an owner. A value equal to the
acting user, a value equal to the stored owner, a rendered owner object and a write
with no session user SHALL NOT be refused, because each is a round trip or a
restore rather than a claim.

#### Scenario: a create naming somebody else as owner is refused

- **WHEN** a signed-in caller creates a record with `@self.owner` set to another user
- **THEN** the create is refused with HTTP 403
- **AND** no record is stored
- @e2e exclude {asserted through the real creation path in SaveObjectOwnerClaimRefusalTest}

#### Scenario: an update naming somebody else as owner is refused

- **WHEN** a signed-in caller updates a record with `@self.owner` set to a third user
- **THEN** the update is refused with HTTP 403
- @e2e exclude {asserted through the real update path in SaveObjectOwnerClaimRefusalTest}

#### Scenario: echoing the stored owner back is accepted

- **GIVEN** a read that emitted `@self.owner`
- **WHEN** the caller sends the same body back unchanged
- **THEN** the write proceeds and the owner is unchanged
- @e2e exclude {round-trip regression, covered by unit tests on the real update path}

#### Scenario: a background write still restores an owner

- **WHEN** an import or a job with no session user writes a record carrying an owner
- **THEN** the owner is stored as given
- @e2e exclude {no session user to drive from a browser}

### Requirement: A group can own a record (REQ-OWN-003)

A record MAY name one owning group in its authorization block. Every member of that
group SHALL be admitted exactly as the named owner is, on every enforcement path:
the single-object verdict and both list emitters. Only the owner or an
administrator SHALL name the owning group, and a group that does not exist SHALL be
refused.

#### Scenario: a colleague in the owning group edits the record

- **GIVEN** a private record owned by `alice` whose owning group is `redactie`
- **WHEN** `bob`, a member of `redactie`, updates it
- **THEN** the update succeeds
- @e2e exclude {group admit path, covered by unit tests on the resolver and its predicate}

#### Scenario: the record stays in the colleague's list

- **GIVEN** a private record owned by `alice` whose owning group is `redactie`
- **WHEN** `bob`, a member of `redactie`, lists the schema's records
- **THEN** the record is present
- @e2e exclude {list predicate asserted as SQL; a browser pass cannot tell it from the verdict}

#### Scenario: a group that does not exist is refused

- **WHEN** an owner names an owning group no instance group answers to
- **THEN** the request is refused with HTTP 400
- @e2e exclude {validation path, covered by unit tests}

### Requirement: A second person takes ownership themselves, and the handover is recorded and announced (REQ-OWN-004)

Anybody the rules admit to UPDATE a record SHALL be able to take ownership of it
without an administrator. The new owner SHALL be the acting user and SHALL NOT be
read from the request. Every handover SHALL write one audit entry whose action is
`ownership_handover` and which names the previous and the new owner. The previous
owner SHALL be notified, naming who took the record.

#### Scenario: a colleague takes a record over

- **GIVEN** a record owned by `alice` that `bob` may edit
- **WHEN** `bob` claims ownership
- **THEN** `bob` is the owner
- **AND** one audit entry with action `ownership_handover` names `alice` and `bob`
- **AND** `alice` is notified
- @e2e exclude {asserted at the service, which is the only path that writes an owner}

#### Scenario: somebody who may not edit cannot take the record

- **GIVEN** a record owned by `alice` that `mallory` may not edit
- **WHEN** `mallory` claims ownership
- **THEN** the request is refused with HTTP 403
- **AND** the owner is unchanged and no audit entry is written
- @e2e exclude {refusal asserted at the service, including that nothing was written}

#### Scenario: taking a record you already own changes nothing

- **WHEN** the owner claims their own record
- **THEN** the response reports no change
- **AND** no audit entry and no notification are produced
- @e2e exclude {idempotence, covered by unit tests}

### Requirement: An owner is reassigned across many records in one action, and each is logged (REQ-OWN-005)

An administrator SHALL be able to assign one record, or many records in one
request, to a named owner. The named owner SHALL exist. One audit entry SHALL be
written PER RECORD. A record that cannot be moved SHALL be reported without
stopping the rest.

#### Scenario: an administrator reassigns a departing colleague's records

- **WHEN** an administrator reassigns three records to `carol` in one request
- **THEN** all three change hands
- **AND** three audit entries are written, one per record
- @e2e exclude {bulk path asserted at the service, where the per-record count is observable}

#### Scenario: a record that cannot be resolved does not lose the batch

- **WHEN** one of the named records does not exist
- **THEN** it is reported in `failed` and the others still change hands
- @e2e exclude {partial-failure path, covered by unit tests}

#### Scenario: an ordinary caller cannot give a record away

- **WHEN** a non-administrator assigns a record to another user
- **THEN** the request is refused with HTTP 403
- @e2e exclude {refusal asserted at the service, including that nothing was written}

### Requirement: An owner change carries to child records (REQ-OWN-006)

A handover MAY carry to the record's children, where a child is a record that
references it. A child SHALL be moved only when its owner is the previous owner of
the parent. The cascade SHALL be off unless the request asks for it, and SHALL
terminate on a cyclic relation graph.

#### Scenario: a child that shared the owner moves with the parent

- **GIVEN** a record owned by `alice` with a child record also owned by `alice`
- **WHEN** the record is reassigned to `carol` with the cascade asked for
- **THEN** both records are owned by `carol`
- **AND** each has its own audit entry
- @e2e exclude {cascade asserted at the service against the relation lookup}

#### Scenario: a child somebody else owns is left alone

- **GIVEN** a record owned by `alice` with a child record owned by `dave`
- **WHEN** the record is reassigned with the cascade asked for
- **THEN** the child is untouched
- @e2e exclude {blast-radius assertion, covered by unit tests}

#### Scenario: the cascade is off unless asked for

- **WHEN** a handover is requested without the cascade
- **THEN** no child is read and none is moved
- @e2e exclude {default behaviour, covered by unit tests}
