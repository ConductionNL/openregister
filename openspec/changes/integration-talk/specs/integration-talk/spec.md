---
status: proposed
---

# Integration: Talk

## Purpose

Surface NC Talk conversations and chat messages linked to OR objects through the registry. A single `talk` integration routes both Chat and Conversation controllers internally.

**Standards**: NC Talk (Spreed) API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## Requirements

### Requirement: Talk Provider Registration

`TalkProvider` registered with id='talk', group='comms', requiredApp='spreed', storage='link-table'. A SINGLE provider routes both chat and conversation concerns.

#### Scenario: Present when Spreed installed

- **GIVEN** Spreed app installed
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** exactly ONE provider with id='talk' MUST be included
- **AND** no separate `talk-chat` or `talk-rooms` providers MUST exist

---

### Requirement: Chat-First Tab

Tab SHALL default to the most recent conversation with a visible compose box. Conversation list SHALL be accessible via sub-tab or expand affordance.

#### Scenario: Object with prior conversation opens to chat

- **GIVEN** an object with one or more linked conversations
- **WHEN** `CnTalkTab` renders
- **THEN** the most recent conversation MUST be displayed
- **AND** a compose box MUST be visible for sending messages

#### Scenario: Object without conversation shows empty state

- **WHEN** `CnTalkTab` renders for an object with zero linked conversations
- **THEN** an empty state with "Start conversation" CTA MUST be shown

---

### Requirement: Unread Count on Dashboard Surfaces

Widget on `user-dashboard` / `app-dashboard` SHALL display unread-message count as the headline metric.

#### Scenario: Unread count rendered

- **GIVEN** the user has 7 unread messages across 3 conversations on their linked objects
- **WHEN** `CnTalkCard` renders with `surface='user-dashboard'`
- **THEN** the headline MUST show "7 unread messages across 3 conversations"

#### Scenario: Clicking headline opens detail

- **WHEN** the user clicks the unread headline
- **THEN** the view MUST expand to show per-conversation unread breakdowns

---

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'talk'` SHALL render `CnTalkCard` at `surface='single-entity'` showing conversation name + unread indicator.

---

### Requirement: Permission Inheritance

`TalkProvider::requiresPermission()` SHALL return `null`. Talk's own room ACLs govern visibility transitively.
