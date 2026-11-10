# platform-team-resources

## ADDED Requirements

### Requirement: A team's page lists the objects that team owns (REQ-PTR-001)

The system SHALL implement a team resource provider that lists, for a
team, the objects whose declared owning-team property names it, each with
its name, icon and link. The listing SHALL be paged with a cursor and
SHALL apply the reading user's access. A schema SHALL declare whether its
objects appear on team pages, defaulting to not appearing.

#### Scenario: an afdeling sees its own cases

- **GIVEN** a schema declaring that its objects appear, and objects owned by a team
- **WHEN** a member of that team opens the team page
- **THEN** those objects are listed with name, icon and link

#### Scenario: a large register answers a page

- **GIVEN** a team owning more objects than one page holds
- **WHEN** the team page is opened
- **THEN** one page is returned with a cursor for the next

#### Scenario: an opted-out schema stays off the page

- **GIVEN** a schema declaring nothing
- **WHEN** the team page is opened
- **THEN** none of its objects is listed

### Requirement: The provider answers whether one object belongs to a team (REQ-PTR-002)

The provider SHALL answer, for one object and one team, whether that
object belongs to the team, resolved from the declared owning-team
property. It SHALL NOT introduce a membership model of its own.

#### Scenario: belonging is read from the property

- **GIVEN** an object whose owning-team property names a team
- **WHEN** the provider is asked whether it belongs to that team
- **THEN** it answers yes, and no for any other team

#### Scenario: access is unchanged by belonging

- **GIVEN** a user who may not read that object
- **WHEN** they open the team page
- **THEN** the object is not listed to them
