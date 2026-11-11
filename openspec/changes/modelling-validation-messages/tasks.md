# Tasks: modelling-validation-messages

## 1. Declaration

- [ ] 1.1 Accept and validate `x-error-messages` on a schema property at save: supported keywords, string or language map, BCP 47 tags, 400 naming property and key. Verify: `SchemaPropertyValidatorTest` cases.

## 2. Resolution

- [ ] 2.1 In `ValidateObject::formatValidationError()`, look up the declared message for the failed keyword and property before the generated one, with `required` resolved to the missing property. Verify: `ValidateObjectCustomMessageTest` for `pattern`, `required` and a property without a message.
- [ ] 2.2 Pick the language through `LanguageService` with the fallback order resolved language, `nl`, first declared, generated. Verify: unit tests with `Accept-Language: en`, with `?_lang=nl`, and with only `en` declared.
- [ ] 2.3 Substitute `{value}`, `{property}` and `{limit}` as plain text, value cut to 100 characters. Verify: unit test with a markup value.
- [ ] 2.4 Keep `keyword` and `property` on each error entry of the 422 body. Verify: API test asserts both next to the custom message.

## 3. Interface and docs

- [ ] 3.1 nextcloud-vue schema-driven form shows the returned message under the field. Verify: component test in nextcloud-vue.
- [ ] 3.2 Open Register's property editor offers a message per keyword and language. Verify: `tests/e2e/schema-validation-messages.spec.ts` writes a Dutch message, creates a bad object, and sees the message.
- [ ] 3.3 `docs/` section on custom validation messages with the postcode example.

Acceptance:
- A schema without `x-error-messages` returns exactly today's messages.
