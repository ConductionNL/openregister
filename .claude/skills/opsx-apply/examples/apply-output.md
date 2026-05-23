<!-- Example output — opsx-apply for change: add-webhook-support -->

Active model: Sonnet ✓

Using change: **add-webhook-support**
Override with: `/opsx-apply <other-change>`

Checking status...
Schema: **spec-driven**

Getting apply instructions...
Context files: proposal.md, specs/webhooks/spec.md, design.md, tasks.md

**Progress:** 0/6 tasks complete

Remaining tasks:
1. Create WebhookSubscription entity and mapper
2. Create WebhookSubscriptionController with CRUD routes
3. Create WebhookDispatcherService with background job
4. Hook dispatcher into ObjectService lifecycle events
5. Write PHPUnit tests
6. Document webhook API in README

> User: Start implementing

---

## Implementing: add-webhook-support (schema: spec-driven)

---

Working on task 1/6: Create WebhookSubscription entity and mapper

Created `lib/Db/WebhookSubscription.php`:
- Entity class extending Entity
- Properties: id, url, secret, events, register, schema, organisation, createdAt, updatedAt
- Getter/setter methods for all properties

Created `lib/Db/WebhookSubscriptionMapper.php`:
- Extends QBMapper
- `findAll(int $limit, int $offset)` — paginated list
- `findByOrganisation(string $org)` — scoped list
- `findByEventAndTarget(string $event, string $register, string $schema)` — for dispatcher

Updated tasks.md: `- [ ] Create WebhookSubscription entity and mapper` → `- [x]`

✓ Task complete

---

Working on task 2/6: Create WebhookSubscriptionController with CRUD routes

Created `lib/Controller/WebhookSubscriptionController.php`:
- `index()` — list subscriptions for current organisation
- `show(string $id)` — get single subscription
- `create(Request $request)` — create new subscription with `@NoAdminRequired @NoCSRFRequired`
- `update(string $id, Request $request)` — update subscription
- `destroy(string $id)` — delete subscription

Updated `appinfo/routes.php` — added 5 routes under `/api/webhook-subscriptions` prefix.

Updated tasks.md: task 2 → `[x]`

✓ Task complete

---

Working on task 3/6: Create WebhookDispatcherService with background job

Created `lib/Service/WebhookDispatcherService.php`:
- `dispatch(string $event, array $payload)` — enqueues a `WebhookDeliveryJob`
- Signs payload with HMAC-SHA256 using subscription secret
- `X-OpenRegister-Signature: sha256=<digest>` header

Created `lib/BackgroundJob/WebhookDeliveryJob.php`:
- Extends `\OC\BackgroundJob\QueuedJob`
- Executes HTTP POST via Guzzle with 3-attempt retry (1s, 2s, 4s backoff)
- Logs result to `oc_openregister_webhook_delivery_log`

Created `lib/Db/WebhookDeliveryLog.php` + `WebhookDeliveryLogMapper.php`

Updated tasks.md: task 3 → `[x]`

✓ Task complete

---

Working on task 4/6: Hook dispatcher into ObjectService lifecycle events

Modified `lib/Service/ObjectService.php`:
- Injected `WebhookDispatcherService` via constructor DI
- `saveObject()` (new objects) → `$this->webhookDispatcher->dispatch('object.created', [...])`
- `updateObject()` → dispatches `object.updated` after successful save
- `deleteObject()` → dispatches `object.deleted` before deletion

Updated `lib/AppInfo/Application.php` — registered `WebhookDispatcherService` in DI container.

Updated tasks.md: task 4 → `[x]`

✓ Task complete

---

Working on task 5/6: Write PHPUnit tests

Created `tests/Unit/Service/WebhookDispatcherServiceTest.php`:
- `testDispatchEnqueuesJobOnObjectCreated()` — verifies job enqueued
- `testNoDispatchWhenNoSubscriptions()` — graceful no-op
- `testPayloadSignatureHeader()` — verifies HMAC-SHA256 in header
- `testRetryBehaviorOnHttpFailure()` — verifies 3 retries

All 4 tests pass. `composer test` output: `OK (42 tests, 156 assertions)`

Updated tasks.md: task 5 → `[x]`

✓ Task complete

---

Working on task 6/6: Document webhook API in README

Updated `openregister/README.md`:
- Added "Webhooks" section with subscription API, payload format, event types, signature verification example

Updated tasks.md: task 6 → `[x]`

✓ Task complete

---

## Quality Checks

Running `composer check:strict`...

| Tool | Status |
|------|--------|
| PHP Lint | ✓ Pass |
| PHPCS | ✓ Pass (2 style issues auto-fixed by phpcbf) |
| PHPMD | ✓ Pass |
| Psalm | ✓ Pass |
| PHPStan | ✓ Pass |
| Unit Tests | ✓ Pass (42 tests, 156 assertions) |

Running `npm run lint && npm run stylelint`...
| ESLint | ✓ Pass |
| Stylelint | ✓ Pass |

---

## Implementation Complete

**Change:** add-webhook-support
**Schema:** spec-driven
**Progress:** 6/6 tasks complete ✓

### Completed This Session
- [x] Create WebhookSubscription entity and mapper
- [x] Create WebhookSubscriptionController with CRUD routes
- [x] Create WebhookDispatcherService with background job
- [x] Hook dispatcher into ObjectService lifecycle events
- [x] Write PHPUnit tests
- [x] Document webhook API in README

**What's Next**
Recommended: `/opsx-verify` | Optional: `/team-reviewer`, `/opsx-sync` | Alternative: `/opsx-archive`
