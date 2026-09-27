---
kind: code
depends_on: [flow-powerful-steps-need-a-right]
---

# Proposal: webhooks-from-flows-and-for-owners

## Summary

A project owner in planninq, or an app builder in buildiq, can subscribe a URL
of their own to the record events of a register and schema they work in,
without asking an administrator to do it for them. They see and manage only
their own subscriptions, and a delivery never carries a record, or a field of
one, that the owner could not read in Open Register themselves. Delivery is the
existing webhook delivery: signed, retried, logged and sent from a background
job. An administrator decides who may own subscriptions, and keeps the
instance-wide ones. A flow reaches these subscriptions through the record
events its writes raise; this change adds no flow node that posts to a URL,
because an open requirement forbids one (see Why).

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| buildiq | int-outbound-webhooks | Send an app's record events to another system as they happen. | partial |
| planninq | int-webhooks | Send a webhook to another system when a task changes. | partial |

Both are rows in sibling matrices (buildiq's and planninq's), owned here
because `built.owner` is ConductionNL/openregister: both apps' records are Open
Register objects and their events are Open Register's.

Demand rows: none recorded in the packet for either row.

Competitor yes cells for buildiq int-outbound-webhooks, quoted from the packet:

- NocoBase: "source read at v2.2.18, not driven: packages/plugins/@nocobase/plugin-workflow/src/client-v2/triggers/collection/index.tsx:21
  collection event trigger plus packages/plugins/@nocobase/plugin-workflow-request/src/client-v2/RequestInstruction.tsx:18
  HTTP request node send record events to another system as they happen; both
  builtIn (packages/presets/nocobase/package.json:172 and 187)". Source path as cited, no URL.
- Budibase: "source read at v3.46.0, not driven: row created, updated and
  deleted triggers (packages/shared-core/src/automations/triggers/rowUpdated.ts:11)
  chained to the 'API request' step (packages/shared-core/src/automations/steps/apiRequest.ts:11)
  send record events out as they happen". Source path as cited, no URL.
- Microsoft Power Apps: "docs-only: https://learn.microsoft.com/en-us/power-apps/developer/data-platform/register-web-hook
  (2026-09-26): register a webhook with the Plug-in Registration tool so
  Dataverse posts record events to an external endpoint as they happen".
  Evidence: https://learn.microsoft.com/en-us/power-apps/developer/data-platform/register-web-hook

Competitor yes cells for planninq int-webhooks, quoted from the packet:

- OpenProject 16 Community: "source read at v17.8.0: modules/webhooks/config/routes.rb:30-38
  admin outgoing webhooks; modules/webhooks/app/models/webhooks/webhook.rb:8
  events per webhook and :24 all_projects; ... work_package.rb:54 work package
  created or updated; modules/webhooks/app/models/webhooks/log.rb delivery log".
  Source path as cited, no URL.
- Plane Community 1.4: "source read at v1.4.2: .../settings/(workspace)/webhooks/page.tsx;
  apps/api/plane/db/models/webhook.py:39-43 event switches project, issue,
  module, cycle, issue_comment; apps/api/plane/bgtasks/webhook_task.py:101-114
  delivery with retry_count". Source path as cited, no URL.
- Kanboard 1.2: "source read at v1.2.54: app/Template/config/webhook.php:7-8
  'Webhook URL', :20 token; app/ServiceProvider/NotificationProvider.php:38
  webhook project notification; app/Notification/WebhookNotification.php:36
  notifyProject, :59 postJson". Source path as cited, no URL.
- Jira Software Data Center 11: "Webhooks are user-defined HTTP POST callbacks.
  They provide a lightweight mechanism for letting remote applications receive
  push notifications from Jira (read 2026-09-26)". Evidence:
  https://confluence.atlassian.com/adminjiraserver/managing-webhooks-938846912.html

## Why

Open Register already delivers record events to a URL, well:

- `WebhookEventListener` turns object events into payloads
  (`lib/Listener/WebhookEventListener.php:166-240`), registered on the object
  events (`lib/AppInfo/Application.php:3570-3576`).
- `WebhookService::dispatchEvent()` enqueues every delivery, first attempt
  included, as a `WebhookDeliveryJob`, so no write waits on a third party
  (`lib/Service/WebhookService.php:634-691`). Delivery signs with HMAC (`:1276`),
  retries on a policy (`:1297-1360`), logs every attempt (`:750-760`) and runs an
  SSRF guard on the target and on every redirect (`:293-415`, `:1191`, `:1242`).

But only an administrator can use it. Every webhook endpoint returns 403 for a
non-administrator: `index` (`lib/Controller/WebhooksController.php:209-213`),
`show` (`:323-327`), `create` (`:373-378`), `update`, `destroy`, `test`, the
logs and retry (`:459-1330`). A webhook has no owner, only an organisation
(`lib/Db/Webhook.php:139`), and an empty event list means every event
(`lib/Db/Webhook.php:390-396`). planninq's matrix says it plainly: "An admin can
subscribe a webhook to planninq task objects in OpenRegister; a planninq user or
project owner cannot."

The flow half of the lead's brief does not hold up, and this change does not
specify it. The open change `flow-messaging-nodes` carries a requirement that
"`activity`, `webhook` and `web-push` SHALL NOT be flow node types", with the
scenario "no `openregister.send-webhook` node MUST exist" and "the documented
path is an `openconnector.source-call` node against a configured source"
(`openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md:149-161`).
`openconnector.source-call` is a live contributed node (recorded off the node
catalogue in `openspec/changes/flow-parity-mapping-and-webhooks/proposal.md`,
section 3). A flow that writes a record also raises the object events these
subscriptions listen to: `ObjectWriteNode` writes per item through the object
service, and only its bulk mode skips per-object events, by design
(`lib/Service/Flow/Nodes/ObjectWriteNode.php:26-28`, `:48-58`). Adding a
post-to-webhook node would contradict that requirement; amending it is the
lead's call, not this change's.

## What changes

- A webhook may have an `owner` (a Nextcloud user). Without one it is an
  administrator's webhook, exactly as today.
- A new action right `webhook.own` in Open Register's action matrix, seeded to
  administrators only, which an administrator grants to the groups that should
  own subscriptions on the action rights screen that
  `flow-powerful-steps-need-a-right` adds (Open Register has no screen for its
  own action matrix at this sha).
- A holder of `webhook.own` may create, list, read, update, test and delete
  their own webhooks and read their logs through the existing `/api/webhooks`
  routes. They never see another person's webhook or an administrator's.
- An owned webhook must name a register and at least one schema, and may only
  subscribe to object created, updated and deleted events. It cannot intercept
  requests, cannot allow private targets and has no empty-means-all event list.
- At delivery an owned webhook sends the record as its owner would read it,
  with property-level rules applied, and sends nothing for a record the owner
  cannot read. A delete sends identifiers only.
- An owner who loses `webhook.own`, is disabled or is deleted stops receiving;
  their webhooks are disabled, not deleted, so an administrator can review
  them.
- The Webhooks page is reachable for holders of `webhook.own` and shows their
  own webhooks.

## Consumers

- planninq (int-webhooks): a project owner subscribes to the task schema. A
  link from planninq's settings to the Webhooks page is planninq's.
- buildiq (int-outbound-webhooks): a builder subscribes a built app's register.
  buildiq's automation compiler can target `openconnector.source-call` for
  flow-side posts; that is buildiq's.

## ADRs

- hydra ADR-005 (security): per-object authorization on every webhook route,
  and no delivery of data the owner could not read.
- hydra ADR-023 (action authorization): `webhook.own` is a named, seeded,
  revocable right.
- hydra ADR-067 (shared egress): owned webhooks go out through the same guarded
  sender as administrators' webhooks, with private targets always refused.
- hydra ADR-091: nothing here authenticates an inbound caller or encodes a
  national standard; this is outbound delivery of Open Register's own events.
- hydra ADR-078: delivery stays asynchronous to the write.
- openregister ADR-002 (organisation tenancy): an owned webhook belongs to the
  owner's active organisation.

## Impact

- Extends the `webhook-payload-mapping` capability.
- Affected code: `lib/Db/Webhook.php` and `WebhookMapper.php` (`owner`, a
  migration, owner-scoped finders), `lib/Controller/WebhooksController.php`
  (owner scoping instead of admin-only), `lib/Service/WebhookService.php`
  (pre-enqueue scope match, owner-view payload), `lib/BackgroundJob/WebhookDeliveryJob.php`,
  `lib/actions.seed.json`, a listener for user deletion and disabling,
  `src/views/webhooks/WebhooksIndex.vue`.
- Backwards compatible: existing webhooks have no owner and behave as today.
  Administrators keep full access.
- Size: M.

## Out of scope

- A flow node that posts to a URL. Forbidden by the `flow-messaging-nodes`
  requirement cited above; flows use `openconnector.source-call`.
- Subscriptions to register, schema, configuration or flow run events for
  owners. Those are instance events and stay with administrators.
- Consolidating the webhook SSRF guard into a shared egress guard. That is
  ADR-067's own sweep.
