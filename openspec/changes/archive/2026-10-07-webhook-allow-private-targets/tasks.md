## 1. Storage via configuration JSON

- [x] 1.1 Read the flag in `lib/Service/WebhookService.php` via `getConfigurationArray()`, casting to bool with a `false` default: `(bool) ($webhook->getConfigurationArray()['allowPrivateTargets'] ?? false)`. No entity field, no `addType`, no migration.
  - Acceptance: a webhook with no `configuration.allowPrivateTargets` key reads as `false` (blocking).

## 2. Controller hydrate path

- [x] 2.1 Confirm the create/update path threads the full `configuration` object (incl. `allowPrivateTargets`) through `WebhookMapper::createFromArray()`/`updateFromArray()` → `Webhook::hydrate()`, and that `jsonSerialize()` returns it (admin-gated; no new endpoint).
  - Acceptance: an admin create/update with `configuration.allowPrivateTargets: true` persists and is returned by the API; round-trips through create and update.

## 3. SSRF guard threading

- [x] 3.1 Add `bool $allowPrivate = false` parameter to `assertSafeWebhookUri()` in `lib/Service/WebhookService.php`; when `true`, skip the IPv4/IPv6 private-range checks while keeping scheme/host/parse checks enforced.
- [x] 3.2 In `sendRequest()` pass `allowPrivate: (bool) ($webhook->getConfigurationArray()['allowPrivateTargets'] ?? false)` to the delivery-time `assertSafeWebhookUri()` call (line ~1109).
- [x] 3.3 In `sendRequest()` override `allow_redirects.on_redirect` per-request to re-validate with the hook's flag (the shared client default stays `allowPrivate = false`).
  - Acceptance: redirect re-validation honours the per-hook flag; default client path stays secure.

## 4. Frontend

- [x] 4.1 Add an always-visible `NcCheckboxRadioSwitch` "Allow private/loopback targets" to `src/modals/webhook/EditWebhook.vue`, bound to `webhookItem.configuration.allowPrivateTargets` (default `false`), and write it into the `configuration` object in the save payload — alongside `useCloudEvents`/`interceptRequests`.
  - Acceptance: toggle shows current value on edit; saving persists it inside `configuration` and round-trips through create/update; i18n key uses the English source string.

## 5. Tests

- [x] 5.1 Add unit tests in `tests/Unit/Service/WebhookServiceTest.php`: flag `true` allows private IPv4 (`http://localhost:8000`), private IPv6 (`http://[::1]:8000`), and a private redirect; non-http scheme still rejected when opted in.
- [x] 5.2 Add a regression test: flag `false`/absent still blocks private/loopback/RFC-1918/link-local IPv4 and IPv6 targets (existing blocking tests must still pass).

## 6. Quality + verification

- [x] 6.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the relevant Hydra gates; fix any pre-existing issues touched.
- [x] 6.2 Bump `appinfo/info.xml` `<version>` for the JS bundle change (NC immutable cache-bust) and rebuild the frontend bundle.

- Add `@spec openspec/specs/notificatie-engine/spec.md` annotations to changed PHP/Vue methods, or a reason-bearing `@spec exclude`.
- Keep SPDX `@license`/`@copyright` docblock tags on any new PHP file.
- This is a code change on a first-class DB entity, NOT a declarative `x-openregister-*` behaviour — no schema-register patch and no seed-data task.
- Storage is Option B (configuration JSON): no entity field, no `addType`, no migration — the flag lives in the existing `configuration` column.
