---
status: done
---

# integration-talk Specification

## Purpose
Links Nextcloud Talk conversations to OpenRegister objects through a single chat-and-rooms provider, present only when Spreed is installed. The tab opens chat-first to the most recent conversation with a compose box, dashboard surfaces show the unread-message count as the headline metric, and `talk` reference properties render a conversation card with an unread indicator. Talk's own room ACLs govern visibility.
## Requirements
### Requirement: Talk Provider Registration

The system SHALL register `TalkProvider` with id='talk', group='comms', requiredApp='spreed', storage='link-table'. A SINGLE provider routes both chat and conversation concerns.

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

#### Scenario: Reference property renders talk card

- **WHEN** a property with `referenceType: 'talk'` is rendered
- **THEN** the system MUST render `CnTalkCard` at `surface='single-entity'` showing the conversation name and unread indicator

---

### Requirement: Permission Inheritance

`TalkProvider::requiresPermission()` SHALL return `null`. Talk's own room ACLs govern visibility transitively.

#### Scenario: Permission inherited from Talk

- **WHEN** `TalkProvider::requiresPermission()` is evaluated
- **THEN** it MUST return `null` so that Talk's own room ACLs govern visibility transitively

