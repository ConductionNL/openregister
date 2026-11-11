# schema-validation-messages

## ADDED Requirements

### Requirement: A property may declare its own validation messages

A schema property MAY declare `x-error-messages`, mapping a validation keyword to a
message that is a string or a map from BCP 47 language tags to strings. The system
MUST refuse at schema save an unsupported keyword, a message of another shape, or an
invalid language tag, with a 400 that names the property and the key.

#### Scenario: A functional administrator writes a Dutch and English message

- **GIVEN** a functional administrator editing the property `postcode` of the schema `meldingen`
- **WHEN** they save a `pattern` message in `nl` and `en`
- **THEN** `GET /api/schemas/{id}` returns the property with both messages under `x-error-messages.pattern`
- @e2e exclude {specified only; task 3.2 adds tests/e2e/schema-validation-messages.spec.ts}

#### Scenario: An unsupported keyword is refused

- **GIVEN** an administrator saving `x-error-messages` with the key `colour`
- **WHEN** the schema is saved
- **THEN** the response is 400 and names `postcode` and `colour`
- @e2e exclude {specified only; task 1.1 adds the validator unit test}

### Requirement: A failed rule returns the declared message in the request language

When a property fails a keyword that has a declared message, the 422 response SHALL
carry that message, chosen in the language the request resolved to, then `nl`, then
the first declared language, and SHALL fall back to the generated message when none
is declared. Each error entry MUST keep its `keyword` and `property`.

#### Scenario: A caseworker sees the Dutch message

- **GIVEN** the `postcode` property with a declared Dutch `pattern` message
- **WHEN** a caseworker whose browser sends `Accept-Language: nl` saves a melding with postcode `ABCD`
- **THEN** the response is 422
- **AND** the error for `postcode` reads "Vul een postcode in zoals 1234 AB." with keyword `pattern`
- @e2e exclude {specified only; task 3.2 adds tests/e2e/schema-validation-messages.spec.ts}

#### Scenario: A field without a message keeps the generated one

- **GIVEN** the property `omschrijving` with `minLength` 10 and no declared message
- **WHEN** a client saves a melding with a three-letter omschrijving
- **THEN** the 422 carries the generated message for `minLength`, unchanged from before this change
- @e2e exclude {specified only; task 2.1 adds the unit test}

### Requirement: Placeholders are substituted as plain text

The system SHALL replace `{value}`, `{property}` and `{limit}` in a declared message
by plain string substitution, SHALL cut the value to 100 characters, and MUST NOT
interpret anything else in the message.

#### Scenario: A submitted value with markup stays text

- **GIVEN** a `maxLength` message "{value} is te lang"
- **WHEN** a client submits `<b>` followed by 300 characters
- **THEN** the message carries the first 100 characters of the value as text, including the literal `<b>`
- @e2e exclude {specified only; task 2.3 adds the unit test}
