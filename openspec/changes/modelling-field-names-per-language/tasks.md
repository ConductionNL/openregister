# Tasks: modelling-field-names-per-language

## 1. Model

- [ ] 1.1 `titles` in `PropertyValidatorHandler::MODIFIERS` with the key and value checks of design D-1. Verify: `PropertyValidatorHandlerTest` refuses `{"xx_bad key": "A"}` and `{"nl": ""}`, accepts `{"nl": "Einddatum", "en": "End date"}`; `PropertyVocabularyTest` lists the modifier.
- [ ] 1.2 Configuration export and import keep `titles`. Verify: a round-trip unit test on `ConfigurationService`.

## 2. Read and edit

- [ ] 2.1 Language projection on schema read with `_lang` and `Accept-Language`, base-language fallback, stored schema without either. Verify: `SchemasControllerTest` for `nl`, `nl-BE`, `de` (falls back to `title`) and no language.
- [ ] 2.2 Per-language name inputs in the schema edit modal's property form. Verify: component test adds and clears a Dutch name.

## 3. Proof and docs

- [ ] 3.1 Add `tests/e2e/ci/field-names-per-language.spec.ts`: give a property Dutch and English names, read the schema with each language, and assert the title.
- [ ] 3.2 Document `titles` in `docs/` beside the property modifiers.
- [ ] 3.3 Open a nextcloud-vue issue to prefer `titles[<user language>]` in `fieldsFromSchema` and table headers; link it here.

Acceptance:
- A schema read without a language is byte-for-byte the stored schema.
