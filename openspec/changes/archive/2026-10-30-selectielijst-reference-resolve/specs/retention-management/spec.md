## ADDED Requirements

### Requirement: A selectielijst URL resolves to its code and name

`GET /api/archival/selectielijst/resolve?url=` SHALL answer the code (`volledigNummer`) and name (`naam`) of a VNG Selectielijst result or process type URL, read once and then from the cache. It SHALL fetch only https URLs on an allowed selectielijst host and SHALL answer 400 for any other URL without a request. When the row cannot be read it SHALL answer 404, so the caller can fall back.

#### Scenario: a Woo result type's class
- **GIVEN** the URL of selectielijst result 6.1 "Ingewilligd"
- **WHEN** a page asks to resolve it twice
- **THEN** both answers SHALL be `{code: "6.1", name: "Ingewilligd"}` and the list SHALL be read once

#### Scenario: another host
- **GIVEN** a URL on another host
- **WHEN** a page asks to resolve it
- **THEN** the answer SHALL be 400 and nothing SHALL be fetched
