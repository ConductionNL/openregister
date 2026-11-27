# Tasks: modelling-validation-messages

## 1. Declaration

- [x] 1.1 Accept and validate `x-error-messages` on a schema property at save: supported keywords, string or language map, BCP 47 tags, 400 naming property and key. Verify: `SchemaPropertyValidatorTest` cases.

## 2. Resolution

- [x] 2.1 In `ValidateObject::formatValidationError()`, look up the declared message for the failed keyword and property before the generated one, with `required` resolved to the missing property. Verify: `ValidateObjectCustomMessageTest` for `pattern`, `required` and a property without a message.
- [x] 2.2 Pick the language through `LanguageService` with the fallback order resolved language, `nl`, first declared, generated. Verify: unit tests with `Accept-Language: en`, with `?_lang=nl`, and with only `en` declared.
- [x] 2.3 Substitute `{value}`, `{property}` and `{limit}` as plain text, value cut to 100 characters. Verify: unit test with a markup value.
- [x] 2.4 Keep `keyword` and `property` on each error entry of the 422 body. Verify: API test asserts both next to the custom message.
      Built in `ValidateObject::handleValidationException()` with `ErrorEntryIdentity`; verified on the response body by `tests/Unit/Service/Object/ErrorEntryIdentityTest.php` (the HTTP probe is the live pass).

  - built (13 Nov): `lib/Service/Schemas/ErrorMessagesDeclaration.php` (called from `PropertyValidatorHandler::assertDeclarations()`), `lib/Service/Object/DeclaredErrorMessage.php` (used first by `ValidationErrorMessages`; the language comes from `LanguageService` through `ValidationLookups::preferredLanguage()`). Tests: `tests/Unit/Service/Schemas/ErrorMessagesDeclarationTest.php` (6), `tests/Unit/Service/Object/ValidateObjectCustomMessageTest.php` (7), red before the change. 2.4 is open: it is verified by an API test against an instance.

## 3. Interface and docs

- [ ] 3.1 nextcloud-vue schema-driven form shows the returned message under the field. Verify: component test in nextcloud-vue.
- [x] 3.2 Open Register's property editor offers a message per keyword and language. Verify: `tests/e2e/schema-validation-messages.spec.ts` writes a Dutch message, creates a bad object, and sees the message.
  - Done (26 Nov): `EditSchemaProperty.vue` has a "Validation messages" section that edits `x-error-messages`: one row per keyword and language, and a row without a language is stored as a single string. The spec lives at `tests/e2e/ci/schema-validation-messages.spec.ts`, the directory CI runs. 3 passed against a sandbox Nextcloud 34.0.6 + OpenRegister main on MariaDB: the form stores `{pattern: {nl: ...}}`, a Dutch client saving `ABCD` reads the declared message with keyword `pattern`, and a property without a message keeps the generated one. The two new strings are translated in all 36 locales.
- [x] 3.3 `docs/` section on custom validation messages with the postcode example.

Acceptance:
- A schema without `x-error-messages` returns exactly today's messages.
