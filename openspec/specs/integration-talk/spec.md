# integration-talk Specification

## Purpose
TBD - created by archiving change integration-talk. Update Purpose after archive.
## Requirements
### Requirement: Talk Provider Registration

`TalkProvider` SHALL be registered with id='talk', group='comms', requiredApp='spreed', storage='link-table'. A SINGLE provider MUST route both chat and conversation concerns; no separate `talk-chat` or `talk-rooms` providers are permitted.

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

#### Scenario: Schema property declares referenceType talk

- **GIVEN** a schema property `chat` with `referenceType: 'talk'` and a value pointing to a Talk room token
- **WHEN** the property is rendered on a detail page
- **THEN** `CnTalkCard` MUST mount at `surface='single-entity'` with `value` set to the room token
- **AND** the chip MUST show the conversation name plus an unread badge when the room has unread messages

---

### Requirement: Permission Inheritance

`TalkProvider::requiresPermission()` SHALL return `null`. Talk's own room ACLs govern visibility transitively.

#### Scenario: Provider declares no extra OR permission requirement

- **WHEN** `IntegrationRegistry` introspects the `talk` provider for required OpenRegister permissions
- **THEN** `requiresPermission()` MUST return `null`
- **AND** Talk's own conversation-level ACL MUST be the only access check applied at list time

