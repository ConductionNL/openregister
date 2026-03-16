## ADDED Requirements

### Requirement: The dashboard MUST display a skill usage pie chart
The LarpingApp dashboard MUST include a donut chart showing the distribution of skills across all characters, powered by data from OpenRegister's GraphQL API.

#### Scenario: Skill usage chart with data
- **GIVEN** 5 characters exist in OpenRegister with skills assigned:
  - "Aldric" with skills: ["Swordsmanship", "Shield Bash"]
  - "Elara" with skills: ["Healing", "Swordsmanship"]
  - "Grimm" with skills: ["Swordsmanship", "Alchemy", "Healing"]
  - "Thorne" with skills: ["Archery", "Stealth"]
  - "Lyra" with skills: ["Healing", "Alchemy"]
- **WHEN** the user views the LarpingApp dashboard
- **THEN** a donut chart MUST display with skills ranked by popularity:
  - "Swordsmanship" = 3 characters
  - "Healing" = 3 characters
  - "Alchemy" = 2 characters
  - "Shield Bash" = 1 character
  - "Archery" = 1 character
  - "Stealth" = 1 character
- **AND** the chart MUST have a title "Skill Usage by Characters"

#### Scenario: Skill usage chart with many skills shows top 10
- **GIVEN** characters reference more than 10 distinct skills
- **WHEN** the chart renders
- **THEN** only the top 10 skills by popularity MUST be shown as individual slices
- **AND** remaining skills MUST be grouped into an "Other" slice with their combined count

#### Scenario: Skill usage chart with no data
- **GIVEN** no characters exist or no characters have skills assigned
- **WHEN** the user views the dashboard
- **THEN** the chart area MUST display a message "No skill data available"
- **AND** no empty chart MUST be rendered

#### Scenario: Chart respects Nextcloud theme
- **GIVEN** the user has Nextcloud dark mode enabled
- **WHEN** the chart renders
- **THEN** chart colors MUST be visible against the dark background
- **AND** labels MUST use appropriate contrast colors

### Requirement: The widget MUST fetch data via two GraphQL queries
The skill usage data MUST be retrieved using GraphQL queries to OpenRegister. Because the `skills` property on characters is a plain UUID array (not a `$ref` relation), two queries are needed: one for characters with skill UUIDs, one to resolve skill names.

#### Scenario: Query 1 fetches characters with skill UUIDs
- **GIVEN** the LarpingApp has characters stored in OpenRegister
- **WHEN** the dashboard loads
- **THEN** the widget MUST execute a GraphQL query requesting characters with their `skills` array
- **AND** the query MUST use the character schema's GraphQL field name (camelCase of the slug)
- **AND** the query MUST request up to 500 characters via `first: 500`

#### Scenario: Query 2 resolves skill names from UUIDs
- **GIVEN** query 1 returned characters with skill UUID arrays
- **WHEN** the widget processes the response
- **THEN** it MUST collect all unique skill UUIDs from all characters
- **AND** execute a second GraphQL query to fetch skill objects with their `name` property
- **AND** use the collected UUIDs to filter the skill query

#### Scenario: GraphQL requests include authentication
- **GIVEN** the user is logged into Nextcloud
- **WHEN** the GraphQL queries execute
- **THEN** each request MUST POST to `/index.php/apps/openregister/api/graphql`
- **AND** each request MUST include the `requesttoken` header from `OC.requestToken`
- **AND** cookies MUST be sent (same-origin request)

#### Scenario: GraphQL query handles errors gracefully
- **GIVEN** the OpenRegister GraphQL endpoint returns an error or is unavailable
- **WHEN** the widget tries to fetch data
- **THEN** the widget MUST display an error message instead of crashing
- **AND** a retry button MUST be available
- **AND** the error message MUST indicate the type of failure (auth, network, GraphQL error)

### Requirement: The widget MUST aggregate skill counts client-side
Skill popularity counts MUST be calculated in the browser from the raw character-skill data.

#### Scenario: Count skill occurrences across characters
- **GIVEN** query 1 returned character objects and query 2 returned skill names
- **WHEN** the widget processes the data
- **THEN** it MUST count how many characters reference each unique skill UUID
- **AND** it MUST map each skill UUID to its resolved name from query 2
- **AND** unresolvable UUIDs MUST be displayed as their UUID string (graceful fallback)

#### Scenario: Handle characters with no skills
- **GIVEN** some characters have empty or null `skills` arrays
- **WHEN** the widget aggregates data
- **THEN** characters with no skills MUST be excluded from the count
- **AND** the chart MUST only show skills that have at least 1 character

### Requirement: The widget MUST integrate with the existing dashboard layout
The chart MUST fit within the LarpingApp dashboard's existing CSS grid infrastructure (DASH-030 through DASH-034).

#### Scenario: Widget placed in graphs grid
- **GIVEN** the dashboard has a `.graphs` CSS grid container (DASH-032)
- **WHEN** the skill usage chart renders
- **THEN** it MUST be placed inside the `.graphs` container
- **AND** it MUST respect the responsive grid (2 columns above 1800px, 1 column below)

#### Scenario: Widget has consistent card styling
- **GIVEN** the dashboard defines card background styles for light/dark themes (DASH-033, DASH-034)
- **WHEN** the chart renders
- **THEN** the chart card MUST use the same background styles as other dashboard cards
- **AND** the card MUST have a heading "Skill Usage by Characters"

### Requirement: LarpingApp MUST be configured for OpenRegister data source
The widget only works when LarpingApp is configured to store characters and skills in OpenRegister (not internal mappers).

#### Scenario: Widget detects OpenRegister configuration
- **GIVEN** LarpingApp's `character_source` is set to `openregister`
- **AND** `skill_source` is set to `openregister`
- **WHEN** the dashboard loads
- **THEN** the widget MUST use the configured `character_register`, `character_schema`, `skill_register`, `skill_schema` values to construct the GraphQL queries

#### Scenario: Widget shows message when not configured for OpenRegister
- **GIVEN** LarpingApp's `character_source` is set to `internal` (default)
- **WHEN** the dashboard loads
- **THEN** the widget MUST display "Configure OpenRegister data source to enable this widget"
- **AND** the widget MUST NOT attempt GraphQL queries
