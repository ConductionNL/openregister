## ADDED Requirements

### Requirement: A changed shipped flow is published as the next version

When an app re-import changes the graph of a flow it declares in
`x-openregister-flows`, the system SHALL publish the new graph as the flow's next
version, provided a version is published, that version was published by an
import (no `publishedBy`), and the flow's head is not an open draft. A version a
person published, or an open draft, SHALL be left as it is. A failure to publish
SHALL NOT abort the import, and the previous version SHALL keep serving.

#### Scenario: An upgrade changes a shipped flow

- **GIVEN** a shipped flow whose version 1 was published by its first import
- **WHEN** the app is upgraded and its declaration now has different nodes
- **THEN** version 2 is published with the new graph
- **AND** version 1 is deprecated
- **AND** `enabled` and `owner` are unchanged

#### Scenario: An administrator published their own version

- **GIVEN** a shipped flow whose published version names the person who published it
- **WHEN** the app is upgraded with a changed declaration
- **THEN** no version is published and the person's version keeps serving

#### Scenario: Nothing changed

- **WHEN** the app is re-imported with the same declaration
- **THEN** no version is published
