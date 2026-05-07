---
status: proposed
---

# Integration: Polls

## Purpose

Link NC Polls to OR objects through the registry. Surfaces poll status, tally, and user's own vote.

**Standards**: NC Polls API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Polls Provider Registration

`PollsProvider` registered with id='polls', group='workflow', requiredApp='polls', storage='link-table'.

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

Per umbrella AD-6/AD-18, `CnPollsCard` SHALL render on all four surfaces (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); the `detail-page` rendering includes a mini bar-chart tally.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'polls'` SHALL render `CnPollsCard` at `surface='single-entity'`.

### Requirement: Permission Inheritance

`PollsProvider::requiresPermission()` SHALL return `null`; Polls' own ACLs apply.

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying poll in NC Polls is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: Closed poll remains accessible after closure

- **GIVEN** a linked poll has been closed in NC Polls (status=closed)
- **WHEN** `CnPollsTab` renders
- **THEN** the poll MUST still be shown with final tally and `status='closed'`
- **AND** vote affordances MUST NOT be rendered

#### Scenario: Deleted poll filters out of list

- **GIVEN** a linked poll was deleted from NC Polls
- **WHEN** `CnPollsTab` renders
- **THEN** the orphaned link MUST be filtered out with a log warning for admin reconciliation
