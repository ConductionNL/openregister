---
retrofit_extensions: ["The system MUST send configurable advance notifications to archivists for objects approaching their archiefactiedatum"]
---

### Requirement: The system MUST send configurable advance notifications to archivists for objects approaching their archiefactiedatum

The `DestructionCheckJob` MUST scan for objects whose `archiefactiedatum` falls within a configurable advance window (default: 30 days) and send per-object Nextcloud notifications to all users in the `archivaris` group. Notifications MUST be deduplicated — each object is notified at most once per retention lifecycle — using a persisted list stored in app config. Objects with active legal holds MUST be excluded. Objects with `archiefnominatie: bewaren` receive an e-Depot transfer subject rather than a destruction warning.

#### Scenario: Objects within lead window trigger advance notifications
- **GIVEN** the `notificationLeadDays` setting is 30 (default)
- **AND** 5 objects have `archiefactiedatum` between today and today+30 days
- **AND** `archiefstatus` is `nog_te_archiveren` for all 5
- **AND** none have been notified before (not in the `retention_notified_objects` app config list)
- **AND** none have an active legal hold
- **WHEN** the `DestructionCheckJob` runs
- **THEN** 5 Nextcloud notifications MUST be sent, one per object, to each user in the `archivaris` group
- **AND** each notification MUST include: object title, `archiefactiedatum`, selectielijst category, and a descriptive subject
- **AND** all 5 object UUIDs MUST be appended to `retention_notified_objects` in app config

#### Scenario: Already-notified objects are not re-notified
- **GIVEN** object `zaak-111` is in the `retention_notified_objects` list
- **AND** `zaak-111` still has `archiefactiedatum` within the lead window
- **WHEN** the `DestructionCheckJob` runs
- **THEN** no notification MUST be sent for `zaak-111`
- **AND** `zaak-111` MUST NOT be added to the notified list again

#### Scenario: Objects with active legal hold excluded from advance notifications
- **GIVEN** object `zaak-222` has `archiefactiedatum` within the lead window
- **AND** `zaak-222` has `retention.legalHold.active` set to `true`
- **WHEN** the `DestructionCheckJob` runs
- **THEN** no advance notification MUST be sent for `zaak-222`

#### Scenario: Objects with archiefnominatie bewaren receive e-Depot transfer subject
- **GIVEN** object `zaak-333` has `archiefnominatie: bewaren` and `archiefactiedatum` within the lead window
- **WHEN** the `DestructionCheckJob` runs
- **THEN** the notification subject MUST be "Object requires e-Depot transfer" rather than "Object approaching destruction date"
