---
kind: code
---

## Why

The webhook delivery pipeline (`WebhookService`) blocks every target that
resolves to a loopback, RFC-1918, link-local, or other private IP range via the
`assertSafeWebhookUri()` SSRF guard. This is correct for production, but it
makes local development impossible: a developer cannot point a webhook at
`http://localhost:8000` (or any in-cluster service) to test deliveries, because
the guard rejects the request before it leaves the instance. We need a
deliberate, admin-controlled escape hatch for local testing that does not weaken
the secure-by-default posture for everyone else.

## What Changes

- Add a **per-hook** boolean flag `allowPrivateTargets` (default `false`) stored
  inside the `Webhook` entity's existing `configuration` JSON field. When set,
  the SSRF guard is bypassed for that hook only.
- Surface the flag as an always-visible toggle "Allow private/loopback targets"
  in the webhook create/edit modal (`EditWebhook.vue`).
- Honour the flag at **delivery time** (`sendRequest()` →
  `assertSafeWebhookUri()`) **and** on **HTTP redirects** (the Guzzle
  `on_redirect` re-validation), so a hook opted into private targets also
  follows private redirects — consistent with the local-testing intent.
- Persist the flag through the existing controller hydrate path
  (`create()`/`update()` → `WebhookMapper` → `Webhook::hydrate()`), inside the
  `configuration` object — no schema change.
- The existing admin-only gating on webhook create/test/retry endpoints
  (wave-3 C10) is the **only** authorization control — no instance-wide flag.

Not a breaking change: existing webhooks default to `false` and keep the current
blocking behaviour.

## Capabilities

### New Capabilities

(none — this extends an existing capability)

### Modified Capabilities

- `notificatie-engine`: The webhook channel's anti-SSRF requirement gains a
  per-hook opt-in. An admin MAY mark a webhook to allow private/loopback
  targets; when set, the SSRF guard MUST be bypassed for that hook at delivery
  AND redirect time, while remaining enforced (default) for every other hook.

## Impact

- **PHP**: `lib/Service/WebhookService.php` (`assertSafeWebhookUri()` signature +
  `sendRequest()` + per-request redirect re-validation, reading the flag from
  `configuration` via `getConfigurationArray()`). No new entity field and no DB
  migration — storage reuses the existing `configuration` JSON column.
- **Frontend**: `src/modals/webhook/EditWebhook.vue` (toggle bound into the
  `configuration` object + payload).
- **Tests**: `tests/Unit/Service/WebhookServiceTest.php` (opt-in allows private
  IPv4/IPv6 + redirects; default still blocks — regression).
- **APIs**: webhook create/update/test JSON payloads gain an optional
  `allowPrivateTargets` boolean; admin-gated, no new endpoints.
- **Security**: localized, opt-in SSRF-guard bypass; secure by default.
