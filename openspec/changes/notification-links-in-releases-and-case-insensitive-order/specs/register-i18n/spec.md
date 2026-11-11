# register-i18n

## ADDED Requirements

### Requirement: Ordering by a text property MUST ignore case

Ordering objects by a plain string property (other than a date or date-time),
by a translatable property, or by the `_name`, `_description` or `_summary`
metadata SHALL compare lower-cased values, on PostgreSQL, MySQL/MariaDB and
SQLite, so the order matches OpenRegister's own lists.

#### Scenario: capitals do not sort first

- **GIVEN** objects titled "Leverancier IBAN-wijziging" and "Leverancier accreditatie"
- **WHEN** a client orders by `title` ascending
- **THEN** "Leverancier accreditatie" comes first
- @e2e exclude {query ordering; covered by tests/Unit/Db/MagicSearchHandlerTranslatableSortTest.php}
