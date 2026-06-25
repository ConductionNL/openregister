## Context

`WebhookService` delivers outbound webhooks and guards every target against SSRF
via `assertSafeWebhookUri()` (`lib/Service/WebhookService.php`, ~lines 242-358).
The guard blocks IPv4 loopback (127.0.0.0/8), RFC-1918 (10/8, 172.16/12,
192.168/16) and link-local (169.254.0.0/16, incl. cloud-metadata
169.254.169.254), and the IPv6 equivalents via `blockedIpv6Reason()`
(~lines 382-451). It is invoked at two points:

1. **Delivery time** — `sendRequest()` calls `$this->assertSafeWebhookUri(uri: $webhook->getUrl())`
   at line 1109 before issuing the request.
2. **Redirect time** — the Guzzle `allow_redirects.on_redirect` callback built in
   `initializeHttpClient()` (~lines 196-206) re-validates every `Location`.

The `Webhook` entity (`lib/Db/Webhook.php`) is a first-class Doctrine/Nextcloud
DB entity (not an OpenRegister schema object). It already carries typed booleans
such as `enabled` (`addType('enabled', 'boolean')`, hydrate at ~line 530) and a
free-form `configuration` JSON column holding flags like `useCloudEvents` and
`interceptRequests`. Hydration flows controller → `WebhookMapper::createFromArray()`
/ `updateFromArray()` → `Webhook::hydrate()`. The create/update/test endpoints in
`WebhooksController` are already admin-gated (wave-3 C10).

The blocker: a developer (Bart) cannot test a webhook against
`http://localhost:8000` because the guard rejects it. We need a deliberate,
admin-controlled, per-hook bypass.

## Goals / Non-Goals

**Goals:**
- Per-hook opt-in `allowPrivateTargets` (default `false`) that bypasses the SSRF
  guard for that hook only, at delivery AND redirect time.
- Secure by default: existing and new webhooks block private targets unless the
  admin explicitly opts in.
- Zero-migration storage: reuse the existing `configuration` JSON field.
- Always-visible toggle in the webhook edit modal.

**Non-Goals:**
- No instance-wide config flag — admin-only endpoint gating is the only gate.
- No relaxation of the http/https scheme restriction (only the IP-range checks
  are bypassed).
- No new declarative `x-openregister-*` behaviour (see decision below).
- No change to the webhook authorization model.

## Decisions

### Declarative-vs-imperative decision (ADR-031)

The `Webhook` is a real Doctrine/NC DB entity (`lib/Db/Webhook.php`), **not** an
OpenRegister schema object. SSRF validation is imperative security code running
inside `WebhookService`, not declarative business logic attached to a schema.
Therefore this is **explicitly NOT** a declarative `x-openregister-*` behaviour
and there is no schema-register patch and no Seed Data section (the Webhook is
not seeded as an OR object). The imperative path is justified: this is a
security-validation toggle on a first-class entity, and the validation it gates
already lives in imperative PHP. Per ADR-011 (reuse-before-build), there is no
existing SSRF-bypass utility in `lib/Formats/`, `lib/Service/`, or
`lib/Handler/` — confirmed by reading; the only SSRF logic is the
`assertSafeWebhookUri()`/`blockedIpv6Reason()`/`isPrivateHost()` trio in
`WebhookService` itself, which we extend rather than duplicate.

### Storage: key in the existing `configuration` JSON (Decision B — chosen)

Store `allowPrivateTargets` (boolean, default `false`) as a key inside the
existing `configuration` JSON column, alongside the flags already kept there
(`useCloudEvents`, `interceptRequests`). **No dedicated DB column and no
migration.** The flag is read via `getConfigurationArray()`, casting the value
to `bool` and defaulting to `false` when the key is absent:

```
$config = $webhook->getConfigurationArray();
$allowPrivate = (bool) ($config['allowPrivateTargets'] ?? false);
```

- **Alternative (A) — rejected:** a dedicated typed entity field +
  `addType('allowPrivateTargets', 'boolean')` + DB migration, mirroring
  `enabled`. Rejected because it requires a schema change/migration for a flag
  that fits naturally in the existing free-form `configuration` JSON next to the
  other webhook behaviour flags. Trade-off accepted: the flag is not
  independently queryable at the SQL/column level (it lives inside the JSON
  blob), which is acceptable for an admin-set, per-hook testing toggle.

### Threading the flag through the guard

`assertSafeWebhookUri()` gains a parameter:

```
private function assertSafeWebhookUri(string $uri, bool $allowPrivate = false): void
```

When `$allowPrivate === true`, the IP-range checks (IPv4 blocks, IPv6
`blockedIpv6Reason` results) are skipped; the scheme check (http/https only) and
parse/host checks remain enforced. Default `false` preserves current behaviour
for every existing caller.

- `sendRequest()` (line 1109) passes
  `allowPrivate: (bool) ($webhook->getConfigurationArray()['allowPrivateTargets'] ?? false)`.

### Redirect re-validation must be per-request

The `on_redirect` callback is currently baked into a single shared
`$this->client` in `initializeHttpClient()`, so it cannot see which hook is in
flight. To honour the flag at redirect time, `sendRequest()` overrides
`allow_redirects.on_redirect` in the per-request Guzzle `$options` it already
builds, capturing the hook's flag:

```
$allowPrivate = (bool) ($webhook->getConfigurationArray()['allowPrivateTargets'] ?? false);
$options['allow_redirects'] = [
    /* same max/strict/protocols as the client default */
    'on_redirect' => function ($req, $res, $uri) use ($allowPrivate): void {
        $this->assertSafeWebhookUri(uri: (string) $uri, allowPrivate: $allowPrivate);
    },
];
```

The shared client default (`allowPrivate = false`) stays as the safe fallback for
any code path that does not set per-request options.

### Entity + persistence

No entity field, no `addType`, and no migration. The flag lives inside the
existing `configuration` JSON column, so the existing hydrate/serialize path
already carries it:

- `Webhook::hydrate()` already accepts and stores `configuration` (the create/
  update payload threads the whole `configuration` object through), so an
  incoming `configuration.allowPrivateTargets` key persists with no new code.
- `jsonSerialize()` already emits `configuration`, so the value round-trips to
  the UI inside that object.
- Reads use `getConfigurationArray()` and cast to bool with a `false` default
  when the key is absent.

### Frontend

`src/modals/webhook/EditWebhook.vue` uses `NcCheckboxRadioSwitch`. Add an
always-visible toggle "Allow private/loopback targets":

```
<NcCheckboxRadioSwitch
  :checked="webhookItem?.configuration?.allowPrivateTargets === true"
  @update:checked="onAllowPrivateChanged">
  {{ t('openregister', 'Allow private/loopback targets') }}
</NcCheckboxRadioSwitch>
```

The flag binds into `configuration` (the same object that already holds
`useCloudEvents`/`interceptRequests`), not a top-level entity field. The
default-object initialiser ensures `configuration.allowPrivateTargets: false`;
the save payload writes `this.webhookItem.configuration.allowPrivateTargets ===
true` into the `configuration` object that is already sent on create/update.
i18n keys are the English source string.

## Risks / Trade-offs

- [Admin enables it in production and points a hook at an internal service] →
  Mitigation: default `false`, admin-only endpoints, explicit per-hook scope,
  clear UI label; the bypass is opt-in and visible.
- [Redirect override drifts from the client default config] → Mitigation: keep
  the same `max`/`strict`/`protocols` values; the only difference is the
  flag-aware `on_redirect`. Covered by a redirect unit test.
- [Existing rows without the key] → Mitigation: the read casts a missing
  `configuration.allowPrivateTargets` to `false`; existing webhooks inherit
  blocking behaviour with no migration. Verified by a regression test (flag
  absent ⇒ private target still blocked).

## Migration Plan

No DB migration. The flag lives in the existing `configuration` JSON column, so
there is no schema change.

1. Deploy service + Vue changes together (the bundle bump is required for the JS
   to load — see NC immutable cache-bust rule).
2. Rollback: revert the code; any persisted `configuration.allowPrivateTargets`
   key is simply ignored and behaviour reverts to full blocking.

## Open Questions

- **Storage column vs JSON — RESOLVED → Option B (configuration JSON).** Store
  the flag as `configuration.allowPrivateTargets`, read via
  `getConfigurationArray()`, no dedicated column and no migration. Trade-off
  accepted: the flag is not independently queryable at the column level (it lives
  inside the JSON blob), which is fine for an admin-set per-hook testing toggle.
