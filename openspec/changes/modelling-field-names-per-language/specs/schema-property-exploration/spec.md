# schema-property-exploration

## ADDED Requirements

### Requirement: A property can carry its name in several languages

A schema property MAY carry a `titles` modifier mapping BCP 47 language tags to
a name. The schema validator SHALL refuse a key that is not a language tag and
a value that is not a non-empty string, naming the property. The property
vocabulary SHALL publish the modifier. Export and import SHALL keep it.

#### Scenario: an administrator names a field in Dutch and English

- **GIVEN** an administrator editing schema `klant` in the schema edit modal
- **WHEN** the administrator gives property `contractEnd` the names "Einddatum contract" for `nl` and "Contract end date" for `en` and saves
- **THEN** the stored property holds `titles: { "nl": "Einddatum contract", "en": "Contract end date" }` beside its `title`
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/field-names-per-language.spec.ts}

#### Scenario: a bad language key is refused

- **GIVEN** the same administrator
- **WHEN** the schema is saved through `PUT /api/schemas/{id}` with `titles: { "dutch language": "Einddatum" }` on a property
- **THEN** the save is refused with 422 naming the property and the key
- @e2e exclude {API contract; covered by PropertyValidatorHandlerTest in task 1.1}

### Requirement: A schema read can answer names in the reader's language

A schema read with a `_lang` parameter or an `Accept-Language` header SHALL
answer each property's `title` from `titles` for that language, then for its
base language, and SHALL keep the stored `title` when neither exists. A read
without a language SHALL return the schema as stored.

#### Scenario: a colleague who works in English

- **GIVEN** the schema from the first scenario
- **WHEN** a colleague's app reads `GET /api/schemas/{id}?_lang=en`
- **THEN** property `contractEnd` has `title` "Contract end date"
- **AND** a read with `_lang=de` has the stored `title`
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/field-names-per-language.spec.ts}
