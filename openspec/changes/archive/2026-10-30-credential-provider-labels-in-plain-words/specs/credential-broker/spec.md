## ADDED Requirements

### Requirement: Provider titles are plain words in the reader's language

Every `title` in the provider catalogue SHALL be written in sentence case and SHALL NOT contain an
em-dash or an en-dash, because the title is what a person reads in the "Add credential" picker.
`GET /api/credentials/providers` SHALL return each title translated through the app's l10n bundle
for the requesting user's language, falling back to the English catalogue title when no translation
exists, and to the identifier when an entry has no title. Provider identifiers SHALL NOT change when
a title is reworded.

#### Scenario: A catalogue title carries no dash

- **GIVEN** the shipped `lib/Settings/credential-providers.json`
- **WHEN** the catalogue is loaded
- **THEN** no entry's `title` contains an em-dash or an en-dash

#### Scenario: The picker receives dash-free titles with unchanged identifiers

- **GIVEN** a signed-in user
- **WHEN** they request `GET /api/credentials/providers`
- **THEN** every returned `title` is free of em-dashes and en-dashes
- **AND** the identifiers `anthropic`, `anthropic-oauth`, `anthropic-cli`, `github-push` and `bluesky` are still returned

#### Scenario: A Dutch reader gets Dutch titles

- **GIVEN** a signed-in user whose language is Dutch
- **WHEN** they request `GET /api/credentials/providers`
- **THEN** the `anthropic` entry's title is "Anthropic Claude API-sleutel"
- **AND** an entry without a Dutch translation keeps its English title
