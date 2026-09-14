# unified-search-provider

## ADDED Requirements

### Requirement: A claiming app has its own search provider identity (REQ-PSP-001)

For each app that claims a (register, schema) pair, the system SHALL
expose a search provider identity carrying that app's id, display name and
order, over the one shared query implementation. A user SHALL be able to
search within one such identity and receive only that app's claimed pairs.
An unclaimed pair SHALL keep the OpenRegister identity.

#### Scenario: a user searches one app alone

- **GIVEN** two apps each claiming their own register and schema
- **WHEN** a user searches within the first app's provider
- **THEN** only objects of that app's claimed pairs are returned

#### Scenario: an unclaimed pair is unchanged

- **GIVEN** a register and schema no app claims
- **WHEN** a user searches everything
- **THEN** its objects appear under the OpenRegister identity, as before

### Requirement: A schema declares its result title, subline and ordering date (REQ-PSP-002)

A schema MAY declare which property provides a search result's title,
which provides its subline, and which date property orders results. Where
a schema declares none, the provider SHALL keep its current derivation.

#### Scenario: a case reads as a case in the search bar

- **GIVEN** a schema declaring a title property and a subline property
- **WHEN** one of its objects matches a search
- **THEN** the entry shows those values

#### Scenario: an undeclared schema behaves as today

- **GIVEN** a schema declaring none of the three
- **WHEN** one of its objects matches
- **THEN** the entry is built exactly as before this change
