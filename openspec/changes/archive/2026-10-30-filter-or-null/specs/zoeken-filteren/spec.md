# zoeken-filteren

## ADDED Requirements

### Requirement: A filter can also admit empty values with orNull

An operator bag on a property filter or a `@self` metadata filter SHALL accept
the modifier `orNull`. When its value reads as true (`true`, `"true"`, `"1"`,
`"on"`, `"yes"`), the field's other operators SHALL still all have to hold, OR
the field SHALL be empty (NULL or not set). When it reads as false, or is
absent, the filter SHALL behave exactly as it does without the modifier. On its
own, `orNull` SHALL add no condition. This SHALL hold on the list and the count,
on PostgreSQL, MySQL and MariaDB, and on paths inside object
properties.

#### Scenario: ne with orNull returns the unwritten row

- **GIVEN** a schema with a boolean property `isFinalStatus` and three objects holding `true`, `false` and no value
- **WHEN** a caller searches with `isFinalStatus[ne]=true&isFinalStatus[orNull]=true`
- **THEN** the objects holding `false` and no value are returned
- @e2e exclude {a query-language rule; covered by tests/Unit/Db/MagicSearchHandlerOrNullModifierTest.php, which runs the generated condition against a real SQLite table}

#### Scenario: ne without orNull keeps its meaning

- **GIVEN** the same objects
- **WHEN** a caller searches with `isFinalStatus[ne]=true`
- **THEN** only the object holding `false` is returned
- @e2e exclude {unchanged behaviour; covered by tests/Unit/Db/MagicSearchHandlerOrNullModifierTest.php}

#### Scenario: orNull on a metadata field

- **GIVEN** objects with and without `@self.expires`
- **WHEN** a caller searches with `@self[expires][lt]=2026-01-01&@self[expires][orNull]=true`
- **THEN** the objects that expire before 2026 and the objects without an expiry are returned
- @e2e exclude {a query-language rule; covered by tests/Unit/Db/MagicSearchHandlerOrNullModifierTest.php on both builders}
