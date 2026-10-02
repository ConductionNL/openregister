# archival-destruction-workflow

## ADDED Requirements

### Requirement: The selectielijst category is declared on the schema and can be overridden per object

A schema SHALL declare its selectielijst category (`archive.classification`, or `category` in `x-openregister-archival`) and MAY name an object property that carries a per-object override (`archive.classificationProperty`, or `categoryProperty`). The effective category of a record SHALL be the override when it is a non-empty string and the schema's category otherwise. Retention derived at creation, the nomination derived at a terminal state, and `retention.classification` (which destruction and transfer routing read) SHALL all follow the effective category. An override naming a category without a selectielijst row SHALL be refused.

#### Scenario: an object without an override takes the schema's category

- **GIVEN** a schema with `archive.classification` `1.1` and `classificationProperty` `selectielijstCategorie`
- **WHEN** an object is created without a value for `selectielijstCategorie`
- **THEN** its `retention.classification` is `1.1` and its retention period is row `1.1`'s
- @e2e exclude {asserted over the real SelectielijstResolver in tests/Unit/Service/RetentionClassificationOverrideTest.php}

#### Scenario: an object with an override takes its own category

- **GIVEN** the same schema and a selectielijst holding rows `1.1` (P5Y, destroy) and `2.3` (P20Y, retain)
- **WHEN** an object is created with `selectielijstCategorie` `2.3`
- **THEN** its `retention.classification` is `2.3`, its nomination and retention period are row `2.3`'s, and the nomination at its terminal state names row `2.3`
- @e2e exclude {asserted in tests/Unit/Service/RetentionClassificationOverrideTest.php and tests/Unit/Service/Archival/ArchivalNominationServiceTest.php}

#### Scenario: an invalid category is refused

- **GIVEN** the same schema
- **WHEN** an object is saved with `selectielijstCategorie` `9.9`, which no selectielijst row has
- **THEN** the save is refused with a validation error naming `9.9`
- @e2e exclude {asserted in tests/Unit/Service/RetentionClassificationOverrideTest.php}

#### Scenario: routing reads the effective category

- **GIVEN** an active object created under the schema's category `1.1`
- **WHEN** it is updated with `selectielijstCategorie` `2.3`
- **THEN** its `retention.classification`, nomination and action date are re-derived from row `2.3`, which is what the destruction sweep and the destruction certificate read
- **AND** the same change on an object already nominated at its terminal state is refused with 409
- @e2e exclude {asserted in tests/Unit/Service/RetentionClassificationOverrideTest.php}
