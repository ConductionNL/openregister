## ADDED Requirements

### Requirement: Tier-2 Dedicated-Mapper Link Services for Optional Peer Apps

The system SHALL provide a family of Tier-2 link services — `AnalyticsLinkService`,
`CalendarLinkService`, `DeckLinkService`, `EmailLinkService`, `FlowLinkService`, and
`PollLinkService` — that persist links between OpenRegister objects and entities owned by
an optional Nextcloud peer app (NC Analytics, Calendar, Deck, Mail, Flow/n8n, Polls) via a
dedicated link mapper rather than the report-name/marker conventions of the Tier-1
providers. Each service MUST resolve the peer app's own services lazily through
`\OCP\Server::get()` behind a `class_exists` guard and an `is*Available()` /
`IAppManager` check, so OpenRegister boots and degrades gracefully when the peer app is
not installed (ADR-019 AD-23).

Each service MUST expose a consistent orchestration surface:

- a **link** operation (`linkReport` / `linkEvent` / `linkCard` / `linkEmail` /
  `linkOperation` / `linkPoll`) that is idempotent — a duplicate link MUST raise a 409 —
  and caches the peer entity's display metadata on the link row at link time;
- a **create-and-link** operation where the peer app supports creation
  (`createAndLinkReport` / `createAndLinkEvent` / `createAndLinkCard` /
  `createAndLinkPoll`) that creates the peer entity then links it, surfacing peer-app
  failures as a 500 and empty required input as a 400;
- an **unlink** operation (`unlinkReport` / `unlinkEvent` / `unlinkCard` / `unlinkEmail` /
  `unlinkOperation` / `unlinkPoll`) that removes only the link row — the peer entity
  itself is left intact — and raises a 404 when no matching link exists;
- a **linked-list** read (`getLinkedReports` / `getLinkedEvents` / `getLinkedCards` /
  `getLinkedEmails` / `getLinkedOperations` / `getLinkedPolls`) that returns the link rows
  for an object, refreshing stale cached metadata from the peer app when available and
  returning the cached values unchanged when it is not;
- a **picker source** read (`getAvailableReports` / `getAvailableCalendars` /
  `getAvailableBoards` / `getAvailableAccounts` / `getAvailableOperations` /
  `getAvailablePolls`) that lists the current user's peer entities for selection,
  returning an empty array when the peer app is unavailable;
- optional **sub-resource pickers** for hierarchical peer apps
  (`getEventsForCalendar`, `getStacksForBoard`, `getMailboxesForAccount`,
  `getMessagesForMailbox`).

#### Scenario: Link is idempotent and caches peer metadata
- **GIVEN** the peer app is available and an object is not yet linked to a peer entity
- **WHEN** the service's link operation runs
- **THEN** a link row MUST be persisted with the peer entity's display metadata cached on it
- **AND** a second link of the same object-to-entity pair MUST raise a 409

#### Scenario: Unlink removes the link only
- **GIVEN** an object linked to a peer entity
- **WHEN** the service's unlink operation runs
- **THEN** the link row MUST be deleted
- **AND** the peer entity MUST remain in the peer app
- **AND** unlinking a non-existent link MUST raise a 404

#### Scenario: Graceful degradation when the peer app is uninstalled
- **GIVEN** the peer app is not installed
- **WHEN** the picker-source read runs
- **THEN** it MUST return an empty array rather than throwing
- **AND** the linked-list read MUST return the cached link-row values unchanged

#### Scenario: Linked-list refreshes stale cached metadata
- **GIVEN** a link row whose cached metadata is older than the service's staleness window and the peer app is available
- **WHEN** the linked-list read runs
- **THEN** the row's cached title/type/timestamps MUST be refreshed from the peer app
- **AND** a refresh failure MUST leave the cached values untouched (the peer entity may have been deleted)
