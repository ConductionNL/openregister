<!-- Example output — opsx-verify for change: add-webhook-support -->

Active model: Sonnet ✓

Using change: **add-webhook-support**

Loading context files: proposal.md, specs/webhooks/spec.md, design.md, tasks.md, plan.json

---

### Dimension 1: Completeness

**Task Completion:**
All 6 tasks in tasks.md are `[x]` — complete.

Syncing plan.json GitHub status...
- Tasks 1–5: status already `done` in plan.json ✓
- Task 6 (document webhook API): was `pending` in plan.json — now synced to `done`
- GitHub issue #142: all task checkboxes updated ✓

**Spec Coverage:**
Delta specs: `openspec/changes/add-webhook-support/specs/webhooks/spec.md`

| Requirement | Found in Code? | Files |
|-------------|----------------|-------|
| Webhook Subscription Management | YES | `lib/Controller/WebhookSubscriptionController.php`, `lib/Db/WebhookSubscriptionMapper.php` |
| Webhook Event Dispatching | YES | `lib/Service/WebhookDispatcherService.php`, `lib/BackgroundJob/WebhookDeliveryJob.php` |
| Delivery Logging and Retry | YES | `lib/Db/WebhookDeliveryLog.php`, `WebhookDeliveryJob.php` |

---

### Dimension 2: Correctness

**Requirement: Webhook Subscription Management**
- `POST /api/webhook-subscriptions` → creates subscription, returns 201 ✓
- `GET /api/webhook-subscriptions` → lists subscriptions scoped to organisation ✓
- `PUT /api/webhook-subscriptions/{id}` → updates subscription ✓
- `DELETE /api/webhook-subscriptions/{id}` → removes subscription ✓
- Scenario "create subscription with invalid URL" → controller validates URL format, returns 422 ✓

**Requirement: Webhook Event Dispatching**
- `WebhookDispatcherService::dispatch()` called in `ObjectService::saveObject()` ✓
- `WebhookDeliveryJob` enqueued as `QueuedJob` (async, not inline) ✓
- HMAC-SHA256 signature in `X-OpenRegister-Signature` header ✓
- WARNING: scenario "subscription filtered by register + schema" — `findByEventAndTarget()` filters correctly, but test only covers `register` filter, not combined `register + schema` filter

---

### Dimension 3: Coherence

**Design Adherence:**
- QueuedJob for async dispatch: implemented ✓
- HMAC-SHA256 signing: implemented ✓
- 3-attempt retry (1s, 2s, 4s backoff): implemented ✓
- Delivery logging: implemented ✓
- Seed data in `lib/Settings/openregister_register.json`: 3 sample subscriptions added ✓ (ADR-001)

**Code Pattern Consistency:**
- `private readonly` DI in constructor: ✓
- Named arguments in all calls: ✓
- Business logic in service, not controller: ✓
- SUGGESTION: `WebhookDeliveryJob.php` is 210 lines — consider extracting delivery logic to `WebhookDeliveryHandler` to follow the Facade+Handler pattern

**Test Coverage:**
- `tests/Unit/Service/WebhookDispatcherServiceTest.php` exists with 4 methods ✓
- WARNING: `WebhookDeliveryJob.php` has no corresponding test file

---

### API Tests (selected)

Testing against nextcloud.local...

| Method | Endpoint | Expected | Actual | Status |
|--------|----------|----------|--------|--------|
| POST | /api/webhook-subscriptions | 201 | 201 | PASS |
| GET | /api/webhook-subscriptions | 200 with array | 200 with array | PASS |
| GET | /api/webhook-subscriptions/{id} | 200 | 200 | PASS |
| PUT | /api/webhook-subscriptions/{id} | 200 | 200 | PASS |
| DELETE | /api/webhook-subscriptions/{id} | 200 | 200 | PASS |
| POST (invalid URL) | /api/webhook-subscriptions | 422 | 422 | PASS |
| GET (wrong org) | /api/webhook-subscriptions | 200 (empty) | 200 (empty) | PASS |

---

## Verification Report: add-webhook-support

### Summary

| Dimension | Status |
|-----------|--------|
| Completeness | 6/6 tasks, 3/3 requirements |
| Correctness | 3/3 requirements covered, 1 partial scenario |
| Coherence | Design followed, 2 minor gaps |
| API Tests | 7/7 PASS |
| Browser Tests | Skipped |

---

### CRITICAL (Must fix before archive)

None.

### WARNING (Should fix)

1. `lib/BackgroundJob/WebhookDeliveryJob.php` — No unit test for this class. Add `tests/Unit/BackgroundJob/WebhookDeliveryJobTest.php` with at minimum: happy-path delivery test, retry-on-failure test.
2. `tests/Unit/Service/WebhookDispatcherServiceTest.php:line 78` — Combined `register+schema` filter scenario not covered. Add test for `findByEventAndTarget()` with both parameters set.

### SUGGESTION

1. `lib/BackgroundJob/WebhookDeliveryJob.php:210 lines` — Consider extracting to `WebhookDeliveryHandler` following the Facade+Handler pattern used elsewhere in this codebase.

---

Found 2 warnings. Would you like me to fix them?

> User: Yes, fix critical only

No critical issues — nothing to fix.

---

All tasks complete. 2 warnings noted.

**Archive this change?**

> User: Yes, archive with warnings

Executing `/opsx-archive add-webhook-support`...
✓ Change archived to `openspec/changes/archive/2026-04-10-add-webhook-support/`
