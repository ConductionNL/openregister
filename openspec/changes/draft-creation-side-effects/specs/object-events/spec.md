# Object events: one rule for a draft

New capability `object-events`, from decision 227. It builds on the explicit lifecycle status `draft` (`form-destination`, decisions 180 and 216).

## ADDED Requirements

### Requirement: Creation side effects MUST be held for a draft and run once when it leaves draft

OpenRegister SHALL decide in one class, `ObjectEventGate`, which event a save or a delete of an object dispatches (decision 227). A listener registered on `ObjectCreatedEvent` SHALL never receive an object in status `draft`, and SHALL receive every object exactly once: when it is created outright, or when it leaves draft. Leaving draft, through the form submit or the owner's activation, SHALL dispatch `ObjectCreatedEvent` once and then `ObjectActivatedEvent` with the moment of receipt, both after the whole write succeeded, so a refused submit announces nothing. No listener SHALL need a status check to follow this rule, and an app that listens to OpenRegister's events SHALL NOT need to know that drafts exist. An object that never was a draft SHALL dispatch the events it dispatched before. Generic by construction (decision 182): the rule reads the object's status and nothing else.

The sixteen listeners on `ObjectCreatedEvent` that this holds for a draft: `ActivityEventListener`, `AggregationThresholdListener`, `AnnotationNotificationListener`, `AssigneeFollowListener`, `AutoTransitionRecordListener`, `ContextChatSubmissionListener`, `FlowTriggerListener`, `GraphQLSubscriptionListener`, `NotifyPushListener`, `ObjectChangeListener`, `ObjectMetricsListener`, `SourceRecordChangeListener`, `WebhookEventListener`, and the three that also sit on the draft path below. Nextcloud's workflow engine and webhook registry subscribe to `ObjectCreatedEvent` by class name too, so they follow the same rule.

`ObjectActivatedEvent` is for the moment of receipt alone. A listener SHALL NOT do creation work on it as well as on `ObjectCreatedEvent`.

#### Scenario: A half-typed object reaches no webhook, flow or notification
@e2e exclude unit; ObjectEventGateTest delivers events the way Nextcloud does, by the event's own class, with an object that is no draft as control

- **GIVEN** a webhook, a flow and a notification rule on "ticket created"
- **WHEN** an agent's draft ticket is saved and autosaved
- **THEN** none of the three runs

#### Scenario: Leaving draft is the creation
@e2e exclude unit; ObjectEventGateTest covers the order and the count, DraftActivationTest and FormSubmitServiceTest the two paths that leave draft

- **GIVEN** the same draft
- **WHEN** its owner makes it definitive
- **THEN** `ObjectCreatedEvent` is dispatched once with the object as it was sent
- **AND** `ObjectActivatedEvent` follows with the moment of receipt
- **AND** no `ObjectUpdatedEvent` is dispatched for the save that left draft

#### Scenario: An object created outright is unchanged
@e2e exclude unit; ObjectEventGateTest covers active, no stored status and a status that only looks like draft

- **WHEN** an object is created in status `active`, or without a stored status
- **THEN** `ObjectCreatedEvent` is dispatched at the save, as before

### Requirement: A draft MUST have events of its own on a separate path

A draft's first save SHALL dispatch `DraftCreatedEvent`, its later saves and the save that leaves draft `DraftUpdatedEvent`, and its delete `DraftDeletedEvent`, each instead of its object counterpart and never beside it. Each SHALL extend its counterpart, so a listener handles it with the code it has. Only the listeners that must see a draft SHALL be registered on them, in one block (`Application::registerDraftListeners()`). A listener belongs there only when it keeps the stored draft itself right and tells nobody anything. The list:

| Listener | Draft events | Why it must see a draft |
|---|---|---|
| `AggregationCacheInvalidationListener` | created, updated, deleted | The owner's counts include their own draft |
| `FacetCacheInvalidationListener` | created, updated, deleted | The owner's lists and facets include their own draft |
| `TranslationProjectionListener` | created, updated, deleted | The sidecar holds the draft's own values per language |
| `ObjectCleanupListener` | deleted | Notes, tasks and links on the draft go with it |
| `WatcherPruneListener` | deleted | Nobody keeps following an object that is gone |
| `ReadStatePruneListener` | deleted | Read state of an object that is gone |
| `NotificationDedupePruneListener` | deleted | Notification keys of an object that is gone |

The listeners that run before a write (`ObjectCreatingEvent`, `ObjectUpdatingEvent`, `ObjectDeletingEvent`: identifiers, calculations, validation, guards, quota) decide what is stored. They are not side effects and SHALL see a draft as before.

#### Scenario: The owner's list follows their draft
@e2e exclude unit; ObjectEventGateTest covers that a listener on the draft events receives every save and the delete of a draft, ObjectEventRuleTest that the cache listeners are on them

- **GIVEN** an agent whose ticket list shows their own drafts
- **WHEN** the agent saves a draft and later discards it
- **THEN** the list and facet caches are invalidated at both moments

### Requirement: A draft's updates and delete MUST stay on the draft path

A save of an object that is still a draft SHALL dispatch `DraftUpdatedEvent` and no `ObjectUpdatedEvent`: an autosave is not a change anybody was told about. A delete of a draft SHALL dispatch `DraftDeletedEvent` and no `ObjectDeletedEvent`: a listener that never saw the object created is not told it is gone. That holds for the owner's discard and for the draft cleanup. This is deliberate and it is the whole rule for a draft's delete: no side-effect listener is told, on any path, and no "deleted" webhook, flow, activity entry or push follows a draft that was never announced. The audit-trail entry of a removed draft (`draft.removed`) is written by the delete itself and does not depend on an event.

#### Scenario: An autosave and a discard reach no side effect
@e2e exclude unit; ObjectEventGateTest covers both, with an ordinary object's update and delete as control

- **GIVEN** a webhook on "ticket updated" and one on "ticket deleted"
- **WHEN** a draft ticket is autosaved and then discarded
- **THEN** neither webhook runs

### Requirement: A generated identifier MUST be issued when the object leaves draft

A draft SHALL NOT take a number from a sequence (decision 201): `GeneratedIdentifierListener` fills nothing and moves no counter on a draft's first save or on a later save that keeps it a draft. The save that takes a stored draft out of draft SHALL issue the number, the way a create does: an empty declared property is filled, a value the object already carries is kept and the counter moves past it, and a counter that cannot answer refuses the save. From then on the number is frozen, as for every object. The listener runs before the write, so it does not receive the gate's events. It SHALL ask `ObjectEventGate` (`holdsCreation()`, `leavesDraft()`) what a creation is, so one class decides that before a write and after it.

#### Scenario: A half-typed case burns no number
@e2e exclude unit; GeneratedIdentifierListenerTest counts the calls to the sequence service on a draft's first save and on its autosave

- **GIVEN** a schema whose `identifier` is generated from the sequence `case`
- **WHEN** a draft is saved, and saved again
- **THEN** it has no identifier and the sequence was not asked

#### Scenario: The number is issued on leaving draft
@e2e exclude unit; GeneratedIdentifierListenerTest covers the save that leaves draft, a number the draft already carried, a counter that is down, and the freeze afterwards as control; ObjectEventGateTest covers the two answers

- **GIVEN** that draft, and the next free number 7
- **WHEN** its owner makes it definitive
- **THEN** the object is stored with the identifier that number 7 renders to
- **AND** a later save that tries to change the identifier is refused

### Requirement: The rule MUST be kept by a test, not by attention

A test SHALL fail when a class other than `ObjectEventGate` creates `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, `ObjectActivatedEvent` or a draft event; when a listener that is not on the list above is registered on a draft event; when a class other than the gate and the registration block names a draft event; and when one listener is registered on both `ObjectCreatedEvent` and `ObjectActivatedEvent`. The test SHALL read the real registration code, not a copy of it.

#### Scenario: A new listener tries to see a draft
@e2e exclude unit; ObjectEventRuleTest, checked by registering a webhook listener on DraftCreatedEvent (it fails) and by creating ObjectCreatedEvent in DraftActivation (it fails)

- **WHEN** a developer registers a new listener on `DraftCreatedEvent` without adding it to the list
- **THEN** `ObjectEventRuleTest` fails and names the event
