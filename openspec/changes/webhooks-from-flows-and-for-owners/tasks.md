# Tasks: webhooks-from-flows-and-for-owners

## 1. Owner and right

- [ ] 1.1 Migration adding `owner` to `openregister_webhooks`; entity field and `WebhookMapper::findOwnedBy()`. Verify: `WebhookMapperTest` for owned and unowned rows.
- [ ] 1.2 Seed `webhook.own: ["admin"]` with its `$why` in `lib/actions.seed.json`. Verify: `tests/Unit/Service/ActionAuthEveryoneTest.php`, which reads the shipped seed file, asserts the entry and that it is not `@authenticated`.

## 2. Controller

- [ ] 2.1 `mayManage()` and owner scoping on all webhook routes, 404 for a hook the caller may not manage, owner and organisation set on create. Verify: `WebhooksControllerTest` for administrator, owner, holder of the right on someone else's hook (404) and a user without the right (403).
- [ ] 2.2 `OwnedWebhookValidator` for events, register and schema scope with a read check, forbidden configuration keys and the 20-hook cap. Verify: `tests/Unit/Service/Webhook/OwnedWebhookValidatorTest.php`, each refusal names its field.

## 3. Delivery

- [ ] 3.1 Pre-enqueue filter for owned hooks in `dispatchEvent()`. Verify: `WebhookServiceTest` asserts no job is added for an owned hook whose scope does not match.
- [ ] 3.2 Owner view in `WebhookDeliveryJob`: owner and right check, re-read as the owner, dropped `oldObject`, identifiers only on delete, private targets refused. Verify: `WebhookDeliveryJobTest` with a property hidden from the owner, an unreadable object, a deleted object and a revoked right.
- [ ] 3.3 Listener disabling a deleted or disabled user's owned hooks. Verify: listener unit test.

## 4. Page, tests and docs

- [ ] 4.1 `WebhooksIndex.vue` for holders of `webhook.own`, menu visibility, texts in en and nl. Verify: component test for the owner view.
- [ ] 4.2 Add `tests/e2e/ci/owned-webhooks.spec.ts`: grant the right to a group, create an owned hook as a member against a local receiver, write an object, assert the delivered body lacks a hidden property, and assert another member gets 404 on the hook.
- [ ] 4.3 Update the webhooks page in `docs/features/` with owned webhooks, the right and the owner view, with a screenshot.

Acceptance:

- A user without `webhook.own` gets 403 on `GET /api/webhooks`, as today.
- No owned hook ever delivers a property its owner cannot read.
