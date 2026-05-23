<!-- Example output — team-reviewer for change: add-webhook-support (uncommitted changes) -->

Detecting changed files...

```bash
git diff --name-only
git diff --cached --name-only
```

Changed PHP files:
- `lib/Controller/WebhookSubscriptionController.php` (new)
- `lib/Db/WebhookSubscription.php` (new)
- `lib/Db/WebhookSubscriptionMapper.php` (new)
- `lib/Service/WebhookDispatcherService.php` (new)
- `lib/BackgroundJob/WebhookDeliveryJob.php` (new)
- `lib/Db/WebhookDeliveryLog.php` (new)
- `lib/Db/WebhookDeliveryLogMapper.php` (new)
- `lib/Service/ObjectService.php` (modified)
- `appinfo/routes.php` (modified)

Changed JS/Vue files: none

---

### Running PHP Quality Pipeline...

**PHPCS:**
```bash
docker exec nextcloud bash -c "cd /var/www/html/custom_apps/openregister && php vendor/bin/phpcs --standard=phpcs.xml lib/Controller/WebhookSubscriptionController.php lib/Db/WebhookSubscription.php ..."
```

3 violations:
- `WebhookDeliveryJob.php:142` — Line length 156 characters (max 150) — ERROR
- `WebhookDispatcherService.php:89` — Named arguments not used in `json_encode($payload, 0, 512)` — ERROR
- `WebhookSubscriptionMapper.php:67` — Variable `$r` is too short (min length 2 per config) — WARNING

**PHPMD:** 1 violation:
- `WebhookDeliveryJob.php` — Method `run()` has cyclomatic complexity of 18 (threshold: 15) — Consider extracting delivery logic into separate method

**Psalm:** 0 errors

**PHPStan:** 0 errors

**Quality Scores:**
| Tool | Score | Threshold | Status |
|------|-------|-----------|--------|
| PHPCS | 96% (2 errors × 2 = 4 deducted) | 90% | PASS |
| PHPMD | 99.5% (1 violation × 0.5 = 0.5 deducted) | 80% | PASS |
| Psalm | 0 errors | 0 new | PASS |
| PHPStan | 0 errors | 0 new | PASS |
| **Composite** | **97.5%** | **90%** | **PASS** |

---

## Code Review: add-webhook-support

### Quality Gate: PASS

| Tool | Score | Threshold | Status |
|------|-------|-----------|--------|
| PHPCS | 96% | 90% | PASS |
| PHPMD | 99.5% | 80% | PASS |
| Psalm | 0 errors | 0 new | PASS |
| PHPStan | 0 errors | 0 new | PASS |
| ESLint | N/A | N/A | N/A |
| Stylelint | N/A | N/A | N/A |
| **Composite** | **97.5%** | **90%** | **PASS** |

---

### Violations

#### MUST FIX (blocks merge)

1. `lib/BackgroundJob/WebhookDeliveryJob.php:142` — Line length 156 chars (max 150) — PHPCS line-length rule
   Fix: Break the Guzzle request options array across multiple lines
2. `lib/Service/WebhookDispatcherService.php:89` — `json_encode($payload, 0, 512)` missing named arguments — must use `json_encode(value: $payload, flags: 0, depth: 512)`

#### SHOULD FIX (improve quality)

1. `lib/BackgroundJob/WebhookDeliveryJob.php:variable $r` — Short variable in loop (minimum 2 chars) — rename `$r` to `$response`
2. `WebhookDeliveryJob::run()` cyclomatic complexity 18 — Extract the retry loop and delivery logic to a `WebhookDeliveryHandler::deliver()` method, following the Facade+Handler pattern used in `ObjectService`

#### SUGGESTIONS (nice to have)

1. `lib/Db/WebhookSubscription.php` — Consider adding `@method` PHPDoc annotations for all getter/setter magic methods — improves IDE support and follows project convention
2. `lib/Service/WebhookDispatcherService.php` — The `dispatch()` method signature could be made more explicit by defining an `WebhookEvent` value object instead of `string $event, array $payload` — not required for this change

---

### Pattern Compliance

| Pattern | Status |
|---------|--------|
| Constructor DI (`private readonly`) | ✓ OK |
| Named arguments | VIOLATION — `json_encode` call missing named args (MUST FIX) |
| Controller thickness | ✓ OK — controllers delegate to `WebhookSubscriptionService` |
| Service pattern | ✓ OK |
| Facade+Handler pattern | VIOLATION — `WebhookDeliveryJob::run()` too complex (SHOULD FIX) |
| Return types on all methods | ✓ OK |
| PHPDoc on public methods | ✓ OK |
| Named arguments throughout | PARTIAL — 1 violation |

### Dutch Government Standards

| Standard | Status |
|----------|--------|
| EUPL-1.2 headers on new files | MISSING — add `SPDX-License-Identifier: EUPL-1.2` to all 7 new PHP files |
| NLGov API pagination | N/A — new endpoints are non-collection (single resource CRUD) |
| AVG/GDPR | OK — no PII logged; webhook payload content is determined by object data |
| OWASP input validation | OK — URL validated, secret stored as bcrypt hash |
| No credentials in code | ✓ OK |

**MUST FIX:** Add SPDX license headers to all 7 new files — required by `reuse lint` and Standard for Public Code.

---

### Auto-fixable Issues

Run these to fix the named-arguments and line-length issues:
```bash
# PHP
composer phpcs:fix
```

PHPCBF will auto-fix the line-length issue in `WebhookDeliveryJob.php`. The named-arguments issue must be fixed manually.

---

### Summary

Quality gate passes at 97.5% composite. Two MUST FIX items: named arguments on `json_encode` and missing SPDX license headers on all 7 new files. The `WebhookDeliveryJob` complexity should be refactored to follow the Facade+Handler pattern before merge, but it's a SHOULD not a blocker. Good overall implementation — clean service separation, proper DI, and correct use of QueuedJob for async dispatch.
