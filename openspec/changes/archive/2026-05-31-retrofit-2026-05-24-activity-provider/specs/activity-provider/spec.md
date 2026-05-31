---
status: draft
---

# Activity Provider — Retrofit Delta (2026-05-24)

## ADDED Requirements

### Requirement: RegisterSetting MUST expose the concrete contract values consumed by the NC Activity app

The `RegisterSetting` ActivitySettings subclass MUST report the following concrete values so the NC Activity settings page renders the row with the correct identifier, group membership, priority order, and default-enabled flags.

#### Scenario: RegisterSetting concrete values
- **GIVEN** an instance of `OCA\OpenRegister\Activity\Setting\RegisterSetting` constructed with an `IL10N` localization service
- **WHEN** the NC Activity settings page queries the setting
- **THEN** the methods SHALL return:
  - `getIdentifier()` = `'openregister_registers'`
  - `getName()` = `$l->t('Register changes')`
  - `getGroupIdentifier()` = `'openregister'`
  - `getGroupName()` = `$l->t('Open Register')`
  - `getPriority()` = `52`
  - `canChangeStream()` = `true`
  - `isDefaultEnabledStream()` = `true`
  - `canChangeMail()` = `true`
  - `isDefaultEnabledMail()` = `false`

### Requirement: An ActivityProvider MUST be exposed as an OpenRegister integration provider for the NC Activity app

In addition to the NC Activity `IProvider` (which parses raw activity events into rich subjects), OpenRegister MUST expose a second-surface `ActivityProvider` that participates in the OR integration-provider registry. This surface lets OR objects show their related NC Activity rows in the registry tab UI. The class MUST extend `AbstractIntegrationProvider` and use the `MarkerLookupTrait`.

#### Scenario: ActivityProvider declares its integration contract
- **GIVEN** an instance of `OCA\OpenRegister\Service\Integration\Providers\ActivityProvider`
- **WHEN** the integration-provider registry queries its contract methods
- **THEN** the methods SHALL return:
  - `getId()` = `'activity'`
  - `getLabel()` = `$l10n->t('Activity')`
  - `getIcon()` = `'Timeline'` (Material Design icon name)
  - `getGroup()` = `'workflow'`
  - `getRequiredApp()` = `'activity'`
  - `getStorageStrategy()` = `'query-time'`
  - `isEnabled()` = `$appManager->isInstalled('activity')`

### Requirement: ActivityProvider MUST list NC Activity rows linked to an OR object via a `[or:{uuid}]` marker

`ActivityProvider::list($register, $schema, $objectId, $filters = [])` MUST return the NC Activity rows whose `oc_activity.subject` column contains the marker `[or:{objectId}]`. The marker convention is the "link-table-on-upstream" variant of the registry storage strategy — the marker lives in the NC Activity table, not in an OR-owned link table. When the NC Activity app is disabled, the method MUST return `[]` instead of querying the DB.

#### Scenario: Disabled NC Activity app returns empty list
- **GIVEN** the NC Activity app is not installed
- **WHEN** `ActivityProvider::list('reg', 'sch', '<uuid>')` is called
- **THEN** the method SHALL return `[]` without querying the database

#### Scenario: Marker query returns mapped leaf rows
- **GIVEN** the NC Activity app is enabled
- **AND** `oc_activity` contains rows whose `subject` column includes the substring `[or:abc-123]`
- **WHEN** `ActivityProvider::list('reg', 'sch', 'abc-123')` is called
- **THEN** the method SHALL invoke `MarkerLookupTrait::findByMarker(db: ..., table: 'activity', markerColumn: 'subject', marker: '[or:abc-123]', extraColumns: ['type', 'affecteduser', 'timestamp', 'object_id'], idColumn: 'activity_id')`
- **AND** SHALL return one entry per matched row shaped as `{id: <activity_id>, title: <subject>, url: '/index.php/apps/activity/<activity_id>', data: <full row>}`

### Requirement: ActivityProvider::health() MUST report NC Activity app availability

`ActivityProvider::health()` MUST return a registry-health response that reflects whether the upstream NC Activity app is installed. Because no per-user authentication is required to query `oc_activity`, the `authStatus` SHALL always be `'configured'`.

#### Scenario: Health response when NC Activity is installed
- **GIVEN** the NC Activity app is installed
- **WHEN** `ActivityProvider::health()` is called
- **THEN** it SHALL return `['status' => 'ok', 'authStatus' => 'configured', 'message' => null]`

#### Scenario: Health response when NC Activity is missing
- **GIVEN** the NC Activity app is not installed
- **WHEN** `ActivityProvider::health()` is called
- **THEN** it SHALL return `['status' => 'unavailable', 'authStatus' => 'configured', 'message' => 'NC Activity app is not installed']`

### Requirement: The account page MUST expose a paginated per-user activity feed with a type filter

The account-page `ActivitySection.vue` component MUST render a paginated list of the current user's activity events, sourced from `GET /apps/openregister/api/user/me/activity`. The list MUST support offset-based paging (default page size 25) and a single-select type filter with the options `'create' | 'update' | 'delete'`.

#### Scenario: Initial load
- **GIVEN** the section is mounted (or the type filter is changed)
- **WHEN** `loadActivity()` runs
- **THEN** it SHALL reset `offset = 0` and `activities = []`
- **AND** SHALL call `GET /apps/openregister/api/user/me/activity?_limit=25&_offset=0` (with `&type=<filter>` appended when a filter is selected)
- **AND** SHALL replace the in-memory `activities` array with `data.results` and set `total = data.total ?? 0`

#### Scenario: Load more
- **GIVEN** `activities.length < total`
- **WHEN** the "Load more" button is clicked
- **THEN** `loadMore()` SHALL increment `offset` by `limit` and call `fetchActivity()`
- **AND** the new page's `results` SHALL be appended to the existing array (not replaced)

#### Scenario: Network errors are swallowed
- **GIVEN** the API call fails
- **WHEN** `fetchActivity()` runs
- **THEN** the error SHALL be silently caught
- **AND** `loading` SHALL be reset to `false` in the `finally` block
