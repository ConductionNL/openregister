# zoeken-filteren

## ADDED Requirements

### Requirement: An object records when a person last wrote it

Every object SHALL carry `@self.lastUserActivity`. A create or update made by a
signed-in user in a web request SHALL set it to the time of the write. A write
made on the command line, from Nextcloud's cron, without a signed-in user,
inside the system-operation scope, or labelled with a write cause other than
`person` SHALL leave it unchanged. `@self.updated` SHALL keep moving on every
write. `@self.lastUserActivity` SHALL be filterable with the same operators as
`@self.updated` and sortable with `_order[@self.lastUserActivity]`.

#### Scenario: a recompute job does not make a case look worked on

- **GIVEN** a case a person last wrote on 1 September
- **WHEN** a background job rewrites one of its fields on 10 October
- **THEN** its `@self.updated` is 10 October
- **AND** its `@self.lastUserActivity` is still 1 September
- @e2e exclude {a background-job write cannot be driven from a browser; covered by tests/Unit/Service/Object/UserActivityStamperTest.php}

#### Scenario: a person's edit moves it

- **GIVEN** the same case
- **WHEN** a signed-in user saves it through the API
- **THEN** its `@self.lastUserActivity` is the time of that save
- @e2e exclude {covered by tests/Unit/Service/Object/UserActivityStamperTest.php; the API save path calls the same stamper}

#### Scenario: stalled cases are found by filter

- **GIVEN** cases last written by a person before and after 1 October
- **WHEN** a caller searches with `@self[lastUserActivity][lt]=2026-10-01T00:00:00`
- **THEN** only the cases a person last wrote before 1 October are returned
- @e2e exclude {a query-language rule; covered by tests/Unit/Db/LastUserActivityMetadataTest.php on both builders}
