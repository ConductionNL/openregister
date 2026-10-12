# Tasks: draft-creation-side-effects

## 1. One place decides

- [x] 1.1 `ObjectEventGate`: `created()`, `updated()`, `deleted()` and `leftDraft()` pick the event by the object's status
  - Spec ref: specs/object-events/spec.md, "Creation side effects MUST be held for a draft and run once when it leaves draft"
  - Test: ObjectEventGateTest
- [x] 1.2 `DraftCreatedEvent`, `DraftUpdatedEvent`, `DraftDeletedEvent`, each extending its object counterpart
  - Spec ref: specs/object-events/spec.md, "A draft MUST have events of its own on a separate path"
  - Test: ObjectEventGateTest
- [x] 1.3 Every dispatch site goes through the gate: `MagicMapper` (insert, update, bulk update, delete), `SaveObjects` (bulk create and update), `DeferredObjectEventJob`; a draft's created event is never deferred
  - Test: ObjectEventRuleTest (no class but the gate creates an object event)
- [x] 1.4 `DraftActivation` and `FormSubmitService` announce leaving draft through `ObjectEventGate::leftDraft()`
  - Test: DraftActivationTest, FormSubmitServiceTest

## 2. The draft path

- [x] 2.1 `Application::registerDraftListeners()`: the seven listeners that must see a draft, in one block
  - Test: ObjectEventRuleTest (the registered listeners equal the list)
- [x] 2.2 `AnnotationNotificationListener` loses its own draft check and its registration on `ObjectActivatedEvent`; it is an instance of the rule now
  - Test: AnnotationNotificationListenerTest (as on development), ObjectEventRuleTest (no listener on both events)
- [x] 2.3 `GeneratedIdentifierListener` asks the gate: a draft takes no number, the save that leaves draft issues it (decision 201)
  - Spec ref: specs/object-events/spec.md, "A generated identifier MUST be issued when the object leaves draft"
  - Test: GeneratedIdentifierListenerTest (four new cases), ObjectEventGateTest::testTheGateSaysWhatACreationIsBeforeAWrite

## 3. Kept by a test

- [x] 3.1 `ObjectEventRuleTest`, checked against two deliberate bypasses
  - Spec ref: specs/object-events/spec.md, "The rule MUST be kept by a test, not by attention"

## 4. Words

- [x] 4.1 `docs/features/form-destination.md`: what a listener sees
- [x] 4.2 `CHANGELOG.md`: the behaviour change for anybody who listens to `ObjectCreatedEvent`

## 5. Verification

- [ ] 5.1 `composer check:strict` once before the push; exit code in the PR body
- [ ] 5.2 Live (decision 139): a webhook and a flow on "created" stay silent on a draft's autosave and run once on activation
