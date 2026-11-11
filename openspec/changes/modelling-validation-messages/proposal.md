---
kind: code
---

# Proposal: modelling-validation-messages

## Summary

A functional administrator writes the message a person sees when a field fails
validation, per rule and per language. A caseworker entering a wrong postcode reads
"Vul een postcode in zoals 1234 AB" in Dutch and the English wording in English,
instead of a fixed English sentence about a pattern. A field without a custom
message keeps today's generated message.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | mod-custom-messages | Write your own wording for a validation error, per field and per language | no |

The row is in Open Register's own matrix, in its core area (modelling).
Demand: feature request, https://github.com/pocketbase/pocketbase/issues/3798.
Competitor rated yes, directus (source read at v12.4.1, not driven): "per field
validation_message directus:packages/system-data/src/fields/fields.yaml:110, shown
on a failed rule by directus:app/src/composables/use-validation-error-details.ts:67
and passed through translateLiteral so a $t: translation key gives per language
wording directus:app/src/stores/fields.ts:173-174".

## Why

`ValidateObject::generateErrorMessage()` (`lib/Service/Object/ValidateObject.php:2144`)
turns an Opis validation error into a message through `formatValidationError()`,
a switch on the failed keyword that builds fixed English strings, for example
"The required property ({property}) is missing. Please provide a value for this
property or set it to null if allowed." The object API returns those strings in the
422 body's `errors` (`lib/Controller/ObjectsController.php:3384`). No schema key
changes the wording, and nothing picks a language.

The request language is already resolved for content: `i18n-api-language-negotiation`
reads `?_lang=`, then the `Accept-Language` header, then the register default,
then `nl` (`lib/Service/LanguageService.php`). Validation messages ignore it.

## What changes

- A schema property may declare `x-error-messages`: a map from a validation
  keyword (`required`, `pattern`, `format`, `minLength`, `maxLength`, `minimum`,
  `maximum`, `enum`, `type`) to a message. A message is a string or a map from a
  BCP 47 language to a string.
- The message may carry `{value}`, `{property}` and the keyword's limit
  (`{limit}`) as placeholders.
- When a property fails a keyword with a declared message, the 422 carries that
  message in the language the request resolved to, falling back to `nl`, then to
  any declared language, then to the generated message.
- The error entry keeps a stable `keyword` and `property`, so a client that
  matches on them does not break.
- nextcloud-vue forms show the returned message beside the field.

## Consumers

- Every leaf app that renders a schema-driven form (dossiq, pipelinq, portaliq
  intake forms) shows the wording its administrator wrote, in the user's language.
- portaliq's citizen forms, where a message a citizen understands is the point.

## ADRs

- hydra ADR-007 and ADR-025 (i18n): Dutch and English at least; a message map is
  data on the schema, not an app string.
- hydra ADR-031: declared on the schema, evaluated by the platform.
- openregister ADR-008 (shared format validators): the keyword set is the one the
  validators already report.

## Impact

- New capability `schema-validation-messages`.
- Affected code: `lib/Service/Object/ValidateObject.php`
  (`formatValidationError()`), the schema property validator that checks the new
  key at save, `lib/Service/LanguageService.php` (read only), nextcloud-vue form
  field error display.
- Backwards compatible: no declared message means today's message.
- Size: S.

## Out of scope

- Translating the generated messages themselves. That is the app string layer
  (`i18n-backend-messages`), not schema data.
- Messages for rules outside JSON Schema keywords, such as uniqueness
  constraints and lifecycle guards, which already carry their own reason.
