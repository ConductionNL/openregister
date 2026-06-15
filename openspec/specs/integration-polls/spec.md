---
status: done
---

# integration-polls Specification

## Purpose
TBD - created by archiving change integration-polls. Update Purpose after archive.
## Requirements
### Requirement: Polls Provider Registration

The system SHALL register `PollsProvider` with id='polls', group='workflow', requiredApp='polls', storage='link-table'.

#### Scenario: Provider registered

- **WHEN** the integration registry is built
- **THEN** the system MUST register `PollsProvider` with id='polls', group='workflow', requiredApp='polls', storage='link-table'

### Requirement: Poll Lifecycle Display

Tab SHALL show each poll's status (draft/open/closed), vote tally, and the current user's own vote.

#### Scenario: Closed poll shows final tally

- **GIVEN** a linked poll with status=closed and tally {yes:7, no:3, abstain:2}
- **WHEN** `CnPollsTab` renders
- **THEN** the row MUST show "Closed • 7 yes / 3 no / 2 abstain"

#### Scenario: User's own vote highlighted

- **GIVEN** the current user voted "yes" on a linked poll
- **WHEN** the tab renders
- **THEN** the user's vote MUST be visually highlighted (e.g., bold or badge)

### Requirement: Widget Surfaces

`CnPollsCard` SHALL render on all four surfaces; detail-page includes mini bar-chart tally.

#### Scenario: Surfaces rendered

- **WHEN** the Polls integration renders
- **THEN** `CnPollsCard` MUST render on all four surfaces, with the detail-page including a mini bar-chart tally

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'polls'` SHALL render `CnPollsCard` at `surface='single-entity'`.

#### Scenario: Reference property renders polls card

- **WHEN** a property with `referenceType: 'polls'` is rendered
- **THEN** the system MUST render `CnPollsCard` at `surface='single-entity'`

### Requirement: Permission Inheritance

`PollsProvider::requiresPermission()` SHALL return `null`; Polls' own ACLs apply.

#### Scenario: Permission inherited from Polls

- **WHEN** `PollsProvider::requiresPermission()` is evaluated
- **THEN** it MUST return `null` so that Polls' own ACLs apply

