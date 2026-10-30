# party-model Specification

## Purpose
Parties are the people and organisations an object involves, such as a requester, a contact or a third party. This spec sets who may write a party, which identity shapes each party kind accepts, and where a party lookup reads first.

## Requirements

### Requirement: A party write needs the update right on the object (REQ-PRM-005)

Giving a party a role on an object, replacing the object's primary party and taking a party off an object SHALL each
require the caller's `update` right on that object, as the object's schema (and a per-object authorization) grants
it. A caller without it SHALL get a 403 and nothing SHALL be written. Reading the parties SHALL need the read right
only.

#### Scenario: a reader cannot link a party

- **GIVEN** an organisation the caller may read but not update
- **WHEN** the caller gives a person a role on it
- **THEN** the answer is 403 and the organisation holds no new party
- @e2e exclude {authorization behaviour, covered by unit tests on the controller}

#### Scenario: a reader cannot unlink a party or replace the primary party

- **GIVEN** an organisation with a main contact, which the caller may read but not update
- **WHEN** the caller takes the main contact off, or names another primary party
- **THEN** each answer is 403 and the organisation's parties are unchanged
- @e2e exclude {authorization behaviour, covered by unit tests on the controller}

#### Scenario: an editor links a party

- **GIVEN** an organisation the caller may update
- **WHEN** the caller gives a person the role `chair` on it
- **THEN** the answer is 201 with the link
- @e2e exclude {authorization behaviour, covered by unit tests on the controller}

### Requirement: A party kind names its identity shapes

A schema's `partyKinds` entry MAY carry `identityShapes`, a list of the identity shapes a party of that kind has in a
shared party-kind vocabulary. The schema SHALL keep the list as distinct, trimmed, non-empty strings, and SHALL return it
with the kind.

#### Scenario: A colleague is a natural person

- **GIVEN** a case schema whose `partyKinds` names `{ key: "colleague", identityShapes: ["naturalPerson"] }`
- **THEN** the party listing's kinds carry `identityShapes: ["naturalPerson"]` for `colleague`

### Requirement: A party is looked up in the party tables first

Looking up a party by id SHALL read the tables of the schemas that declare `x-openregister-party`, in every register
that carries them, before any instance-wide search. Only an id none of them holds SHALL be searched for across the
instance.

#### Scenario: A party in a party table
- **GIVEN** schema 7 declares `x-openregister-party` and register 3 carries it
- **WHEN** party `org-a`, stored in register 3 schema 7, is looked up
- **THEN** only the register 3 / schema 7 table is read

#### Scenario: An id outside the party tables
- **WHEN** an id the party tables do not hold is looked up
- **THEN** the instance-wide lookup still answers it
