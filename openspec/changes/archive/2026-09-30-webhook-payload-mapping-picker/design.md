# Design: webhook-payload-mapping-picker

Read at openregister development `b876628280`.

## What exists

| Piece | Where |
|---|---|
| Webhook dialog | `src/modals/webhook/EditWebhook.vue` |
| Mapping application | `lib/Service/WebhookService.php` applyMappingTransformation |

## Approach

1. NcSelect with `inputLabel` bound to the webhook mapping field; a preview endpoint on WebhooksController that calls the same transformation.

## Declarative or imperative

Imperative UI over an existing field.

## Tests

- vitest: choosing a mapping sends its id on save. NOT WRITTEN (30 Sep): this repo's jest setup has no component-mount harness for modals (no spec mounts a .vue file). The save sends `mapping` explicitly (EditWebhook.vue saveWebhook) and the backend side of saving and clearing it is covered by testANullMappingClearsItAndAnAbsentOneKeepsIt.
- PHPUnit: the preview endpoint returns what the delivery would send. As built: compared with buildPayload() itself, and a missing mapping is reported as `mapped: false` rather than shown as mapped.
