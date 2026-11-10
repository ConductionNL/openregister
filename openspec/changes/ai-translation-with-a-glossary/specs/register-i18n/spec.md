# register-i18n

## ADDED Requirements

### Requirement: AI translation runs through Nextcloud's Task Processing when an administrator enables it

Open Register SHALL offer a translation provider that uses Nextcloud's Task Processing: the `core:text2text:translate` task type when no glossary entry or style guide applies to a field, and the `core:text2text` task type with an instructed prompt when one does. The provider SHALL be bound only when an administrator has chosen it and confirmed that record text may be sent to the Task Processing provider Nextcloud reports; otherwise the identity provider SHALL stay bound. A property flagged `x-openregister-encrypted` SHALL never be passed to a provider and SHALL be skipped with reason `encrypted-at-rest`.

#### Scenario: an administrator switches AI translation on

- **GIVEN** a Nextcloud instance with a Task Processing provider for `core:text2text:translate`
- **WHEN** a functional administrator opens the "Translation" section of the Open Register admin settings, chooses Task Processing, and confirms that record text is sent to that provider
- **THEN** the section shows the provider's name and whether it runs locally
- **AND** the connection registry reports the translation connection as `configured` with that provider
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/ai-translation-glossary.spec.ts}

#### Scenario: nothing is sent while the setting is off

- **GIVEN** AI translation is not enabled
- **WHEN** an administrator calls `POST /api/translations/object/{uuid}/bulk-translate` with `from` `nl` and `to` `en`
- **THEN** the identity provider answers and no Task Processing task is created
- @e2e exclude {specified only; task 2.2 covers it in tests/Unit/Service/BulkTranslationServiceGlossaryTest.php}

#### Scenario: an encrypted field stays home

- **GIVEN** AI translation is enabled and schema `persoon` has a translatable property `toelichting` flagged `x-openregister-encrypted`
- **WHEN** an administrator translates a person record
- **THEN** `toelichting` is listed under `skipped` with reason `encrypted-at-rest`
- **AND** no Task Processing task contains its text
- @e2e exclude {specified only; task 2.2 covers it in tests/Unit/Service/BulkTranslationServiceGlossaryTest.php}

### Requirement: A shared glossary and style guide steer the translation

Open Register SHALL keep glossary terms and style guides as objects in a `translation-glossary` register. A term SHALL name its source and target language, the required translation or that it is not to be translated, whether it is case sensitive, and optionally the register it applies to. A style guide SHALL name its target language, a formality and instructions of at most 2,000 characters. For each field the provider SHALL apply at most 50 terms that occur in the source text, longest first, and the style guide for the target language, and SHALL fence the record text so text inside it is not read as an instruction.

#### Scenario: an agreed term is used

- **GIVEN** a glossary term `omgevingsvergunning` from `nl` to `en` with translation `environmental permit`
- **WHEN** an administrator translates a record whose `omschrijving` reads "Aanvraag omgevingsvergunning voor een dakkapel" from `nl` to `en`
- **THEN** the `en` slot of `omschrijving` contains "environmental permit" and has status `machine_translated`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/ai-translation-glossary.spec.ts}

#### Scenario: a name is left alone

- **GIVEN** a glossary term `DigiD` marked do not translate
- **WHEN** a record mentioning DigiD is translated to `de`
- **THEN** the `de` slot contains `DigiD` unchanged
- @e2e exclude {specified only; task 2.1 covers it in tests/Unit/Service/Translation/TaskProcessingTranslationProviderTest.php}

### Requirement: A translation that misses a glossary term is a draft

After each translation the provider SHALL check that every applied term's required translation, or the untranslated term, occurs in the output. When one does not, the slot SHALL be stored with status `draft` instead of `machine_translated`, and the result SHALL name the missing terms under the property.

#### Scenario: a missed term goes to a person

- **GIVEN** the `omgevingsvergunning` term and a model that answers "building permit"
- **WHEN** an administrator translates the record
- **THEN** the `en` slot is stored with status `draft`
- **AND** the dialog lists `omschrijving` as drafted with "check: omgevingsvergunning"
- @e2e exclude {specified only; the fake provider cannot miss a term, task 2.2 covers it in tests/Unit/Service/BulkTranslationServiceGlossaryTest.php}

### Requirement: An administrator translates a record from its page

The object page SHALL offer a "Translate" action to administrators when the object's schema has at least one translatable property and its register has more than one language. The action SHALL open the bulk translate dialog with the register's languages. After a successful run the page SHALL persist the translated values onto the object and reload it, and the dialog SHALL list what was translated, drafted and skipped.

#### Scenario: an administrator translates a record

- **GIVEN** register `producten` with languages `nl` and `en`, and a product whose `naam` and `omschrijving` are translatable and have only `nl` values
- **WHEN** a functional administrator opens the product, chooses "Translate" in the actions menu, picks `nl` to `en` and confirms
- **THEN** the dialog reports two translated fields
- **AND** after closing it the product shows English values for `naam` and `omschrijving`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/ai-translation-glossary.spec.ts}

#### Scenario: no action where there is nothing to translate into

- **GIVEN** a register with only `nl`
- **WHEN** a functional administrator opens one of its records
- **THEN** the actions menu has no "Translate" entry
- @e2e exclude {specified only; task 3.2 covers it in src/views/object/ObjectDetails.spec.js}
