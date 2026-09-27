# Design: webhooks-from-flows-and-for-owners

Read at openregister development c53dd0685c.

## D-1: an owner on the webhook

`openregister_webhooks` gains a nullable `owner` (uid), mapped on
`lib/Db/Webhook.php` beside `organisation` (`:139`). Null means an
administrator's webhook and changes nothing. `WebhookMapper` gains
`findOwnedBy(string $uid)`; `findForEvent()` (`lib/Db/WebhookMapper.php:319-330`)
is unchanged and still returns both kinds.

## D-2: the right to own, seeded narrow

`lib/actions.seed.json` gains `"webhook.own": ["admin"]`, with a `$why` entry in
the same style as `$why-correcting-is-admin-only`: nobody but an administrator
could create a webhook yesterday, so seeding it to administrators locks nobody
out, and listing it makes it visible in Admin Settings so an administrator can
grant it to, for example, planninq's project owners. The check is
`OpenRegisterActionAuthService::can()` through the same `FlowAccess`-style seam
the flow endpoints use (`lib/Service/Flow/FlowAccess.php:92-94`).

## D-3: the controller scopes by owner instead of refusing

`WebhooksController::isCurrentUserAdmin()` (`lib/Controller/WebhooksController.php:153-164`)
stays for the administrator path. A new private `mayManage(Webhook $hook): bool`
answers true for an administrator, and for anyone else only when the hook's
`owner` is the caller and the caller holds `webhook.own`.

- `index` (`:209`): an administrator sees all, as today; a holder of
  `webhook.own` sees `findOwnedBy(uid)`; anyone else gets 403.
- `show`, `update`, `destroy`, `test`, `logs`, `logStats`, `retry`: a hook the
  caller may not manage answers 404, not 403, so ids cannot be probed.
- `allLogs` (`:1202`) filters to the caller's hooks for a non-administrator.
- `create` (`:373`) by a non-administrator sets `owner` to the caller and
  `organisation` to the active organisation, and ignores any `owner` in the body.

An administrator can see and disable an owned webhook but does not become its
owner by editing it.

## D-4: what an owned webhook may be

Validated on create and update by a new `OwnedWebhookValidator`, answering 422
naming the field:

- `events` must be a non-empty subset of the object created, updated and
  deleted event classes. An empty list, which means every event for an
  administrator's hook (`lib/Db/Webhook.php:393-396`), is refused.
- `filters` (`lib/Db/Webhook.php:146`, evaluated by
  `WebhookService::passesFilters()` at `lib/Service/WebhookService.php:951-973`,
  which already supports a list as "in") must hold `register` (one id) and
  `schema` (one or more ids), and the owner must be able to read that register
  and each schema.
- `configuration.interceptRequests` (read at `WebhookService.php:1525`) and
  `configuration.allowPrivateTargets` (change `webhook-allow-private-targets`)
  may not be set. Interception blocks writes and private targets reach the
  instance's own network; both stay an administrator's.
- A user may own at most 20 webhooks (app config `webhookOwnerMax`).

## D-5: delivery shows what the owner may see

`dispatchEvent()` (`WebhookService.php:634-691`) runs `passesFilters()` before
enqueueing an owned hook, so a busy instance does not queue a job per owned hook
per event only to drop it later. `WebhookDeliveryJob::run()`
(`lib/BackgroundJob/WebhookDeliveryJob.php:130-170`) then, for an owned hook:

1. confirms the owner exists, is enabled and still holds `webhook.own`;
   otherwise it disables the hook and delivers nothing;
2. for a created or updated object, re-reads it inside
   `ObjectService::runAs(owner)` (`lib/Service/ObjectService.php:539`) through
   `ObjectService::find()` with RBAC and multitenancy on, and replaces
   `object` or `newObject` in the payload with that read. `oldObject` is dropped:
   an earlier version may hold values the owner cannot see now. A record the
   owner cannot read is not delivered and the log entry says so;
3. for a deleted object, sends `uuid`, `register`, `schema` and the event name
   only.

Signing, retries, the delivery log and the SSRF guard (`:293-415`, applied at
`:1191` and on redirects at `:1242`) are the existing ones, with private targets
always refused for an owned hook.

## D-6: an owner who leaves

A listener on `OCP\User\Events\UserDeletedEvent` and on `UserChangedEvent` for
the `enabled` feature disables the user's owned webhooks. It disables rather
than deletes, so an administrator can review and hand them to someone else.

## D-7: the page

`src/views/webhooks/WebhooksIndex.vue` shows the caller's own webhooks for a
holder of `webhook.own`, with the register and schema pickers required and the
interception and private-target toggles hidden. The menu entry
(`src/manifest.json`, id `Webhooks`, order 95) is shown to holders of the right.

## Declarative-vs-imperative decision

Imperative, in the webhook service. A webhook subscription is a configured
delivery, not a schema-declared rule: `x-openregister-notifications` (ADR-031)
notifies people, and its webhook channel is an administrator's channel on a
schema. A person-owned subscription belongs to the person, not to the schema,
so it cannot live in the schema's declaration.

## Risks

- Security (hydra ADR-005): the owner view in D-5 is the whole point. A
  delivery built from the event's own `jsonSerialize()`
  (`lib/Listener/WebhookEventListener.php:171`, `:183-184`) would carry every
  property, including those property-level rules hide from the owner. The e2e
  asserts a hidden property is absent from a delivered body.
- Exfiltration: an owner can send only what they can already read through the
  API. The right is seeded to administrators, and private targets are refused.
- Load: the pre-enqueue filter and the 20-hook cap bound the extra jobs. Each
  owned delivery costs one object read as the owner.
- Multitenancy (openregister ADR-002): the re-read runs with multitenancy on, so
  an object of another organisation is never delivered to an owner outside it.
