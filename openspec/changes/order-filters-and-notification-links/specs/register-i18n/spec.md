# register-i18n

## ADDED Requirements

### Requirement: Ordering by a translatable property MUST follow the value a person sees

When a search orders by a property marked `translatable: true`, the order SHALL
compare the value the response shows for each row: the value under the first
language of the resolved chain (accepted request languages the register
offers, then the register's languages, then its default language), else any
value of the language map. A row that holds a plain string instead of a
language map SHALL sort by that string. Plain rows and language-map rows SHALL
form one order, never two runs. Non-translatable properties and metadata
columns SHALL keep ordering on their stored column.

#### Scenario: mixed plain and language-map titles sort as one list

- **GIVEN** a schema whose `title` is translatable, in a register with default language `nl`
- **AND** rows whose titles are stored as `{"nl": "Cultuursubsidie"}`, `"College-besluit"`, `{"nl": "Woo-verzoek"}` and `"Toezichtzaak Milieu"`
- **WHEN** a client searches with `_order={"title":"asc"}`
- **THEN** the rows come back as College-besluit, Cultuursubsidie, Toezichtzaak Milieu, Woo-verzoek
- @e2e exclude {backend query order; covered by tests/Unit/Db/MagicSearchHandlerTranslatableSortTest.php against a real SQLite engine}

#### Scenario: the accepted language decides the order

- **GIVEN** the same schema in a register offering `nl` and `en`
- **AND** rows `{"nl": "Aanvraag", "en": "Zoning"}` and `{"nl": "Zienswijze", "en": "Appeal"}`
- **WHEN** a client that accepts `en` orders by title ascending
- **THEN** the row shown as Appeal comes before the row shown as Zoning
- @e2e exclude {backend query order; covered by tests/Unit/Db/MagicSearchHandlerTranslatableSortTest.php}
