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
- [x] 5.2 Live (decision 139): a webhook and a flow on "created" stay silent on a draft's autosave and run once on activation
  - 2026-11-25, on a sandbox Nextcloud 34 on MariaDB: a draft created with a required field empty and autosaved twice started no run of a published sync flow on `object.created` and no delivery attempt of a webhook on `ObjectCreatedEvent`; activating it ran the flow once and produced one delivery, whose payload carries `@self.status` `active`; a later update added neither. An object created as active ran the flow once (control). The flow half is pinned in `tests/e2e/ci/draft-creation-side-effects.spec.ts` (3 passed). The webhook half was read off the webhook's delivery log and a local receiver; it rides `WebhookDeliveryJob`, so it needs a background-job pass and, for a loopback receiver, `configuration.allowPrivateTargets`, and is not in the spec.
  - Seen on the way: `docs/features/webhooks-and-notifications.md` shows `events: ["nl.conduction.openregister.object.created"]`, but `Webhook::matchesEvent()` matches event class names (`OCA\OpenRegister\Event\ObjectCreatedEvent`), so the documented form never fires. Not changed here.
  - 2026-11-25, sandbox Nextcloud 34 on PostgreSQL 16, main 292aa09: a schema requiring `subject` and `channel`; a
    webhook on `ObjectCreatedEvent` (`allowPrivateTargets`, to a local receiver) and a published flow
    `openregister.trigger-object` (`object.created`, the register and schema) -> `openregister.end`, sync.
  - Control: an ordinary create gave one flow run at once and one webhook delivery after `cron.php`.
  - A draft (`@self.status: draft`, `channel` missing) and two autosaves (`PATCH`, 200 each), then `cron.php`:
    no delivery and no run (deliveries 2 -> 2, runs 1 -> 1, the draft's uuid in no delivery).
  - `POST .../activate` with `channel` (200): one flow run naming the draft's uuid at once; one delivery after
    the queued job ran (`ObjectCreatedEvent`, action `create`, `@self.status: active`, `channel: phone`). A further
    `cron.php` delivered nothing more: one delivery, one run. Webhook logs: every delivery `success`, 200.
