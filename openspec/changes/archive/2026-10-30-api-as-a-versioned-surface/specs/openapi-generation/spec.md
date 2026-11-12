# openapi-generation

## ADDED Requirements

### Requirement: Two contract versions are served at once, with a declared lifecycle (REQ-AVS-005)

An API version SHALL carry a status of supported, deprecated with an end
date, or withdrawn. A supported and a deprecated version SHALL be served
at the same time, each described by its own generated OpenAPI document. A
deprecated version SHALL answer normally and SHALL carry its end date in
the response. A withdrawn version SHALL answer 410 naming its successor.

#### Scenario: five suppliers move at their own pace

- **GIVEN** version 1 deprecated with an end date and version 2 supported
- **WHEN** a client calls version 1
- **THEN** the call succeeds and the response names the end date

#### Scenario: a withdrawn version says where to go

- **GIVEN** version 1 withdrawn with version 2 as its successor
- **WHEN** a client calls version 1
- **THEN** the response is 410 and names version 2

#### Scenario: each version has its own description

- **GIVEN** two versions served at once
- **WHEN** the OpenAPI documents are fetched
- **THEN** each describes only its own routes
- @e2e exclude {generator output, covered by unit tests}
