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

- vitest: choosing a mapping sends its id on save.
- PHPUnit: the preview endpoint returns what the delivery would send.
