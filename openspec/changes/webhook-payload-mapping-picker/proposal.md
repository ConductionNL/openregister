---
kind: code
depends_on: []
---

# Proposal: webhook-payload-mapping-picker

## Summary

An administrator picks a mapping for a webhook in the webhook dialog and sees a preview of the payload the receiver will get. The mapping already runs when it is set over the API; the dialog has no field for it.

## The rows this closes

Source matrix: openregister `openspec/parity/capabilities.json` (comparedOn 2026-09-25). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### auto-webhook-shape, shape the webhook payload for the system that receives it

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `automation`, source `own-code-derived`.

Matrix evidence, verbatim:

> lib/Service/WebhookService.php:1022 applies a Mapping via applyMappingTransformation :1090 (lib/Db/Webhook.php:237 mapping id); src/modals/webhook/EditWebhook.vue has no mapping field (only responseMapping :507), so it is set over PUT /api/webhooks/{id} (routes.php:1918)

Matrix note, verbatim:

> The payload mapping runs, but no screen lets you pick one.

Competitor cells rated `yes`, verbatim:

- directus: source read at v12.4.1, not driven: directus:api/src/operations/request/index.ts:10 body and :11 headers are templated with {{$trigger}} and previous step data; directus:api/src/operations/transform/ builds any JSON payload first
- nocodb: source read at 2026.09.0, not driven: nocodb:packages/nocodb/src/utils/webhook-invoker.ts:515-524 populateAxiosReq builds method, headers and body from the hook's payload template with record variables; docs https://nocodb.com/docs/product/account-settings/cloud-enterprise-edition/community-vs-paid-editions list Webhooks with custom payload in Community Edition

## Why

Two competitors let an administrator shape the webhook payload for its receiver on screen. OpenRegister applies a mapping to the outgoing payload, but only for someone who knows to PUT a mapping id, so administrators build a second integration to reshape it.

## What is built today

- `lib/Service/WebhookService.php` applies a Mapping through applyMappingTransformation; `lib/Db/Webhook.php` carries the mapping id.
- `src/modals/webhook/EditWebhook.vue` has only a response mapping field.

## What changes

1. The webhook dialog gets a payload mapping select listing the mappings the administrator can read, and a clear option.
2. A preview button renders the mapped payload for the last event of the webhook (or a sample object) through the same transformation.

## Out of scope

- Editing the mapping itself from the webhook dialog (it links to the mapping screen).
