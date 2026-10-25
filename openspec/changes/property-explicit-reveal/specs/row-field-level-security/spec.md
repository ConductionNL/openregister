# row-field-level-security Delta

## ADDED Requirements

### Requirement: An explicit-reveal property is masked on every route until one object read asks to see it

A property whose authorization declares `"reveal": "explicit"` SHALL leave OpenRegister as its mask (five stars and
its last four characters) on every read route (object renders, lists, GraphQL, access links, MCP resources, exports,
history), for every reader including administrators. A single-object read naming the property in `_reveal` SHALL
answer the value of that object only, only when the reader may read the property, and SHALL record the reveal when
the property is audited. Filtering and sorting on the property SHALL be refused, and it SHALL take no part in
free-text search or facets. A write that sends the mask SHALL keep the stored value. A system operation and
explicitly trusted server code SHALL see the value.

#### Scenario: The BSN on a contact page

- **GIVEN** brpPerson.citizenServiceNumber with read rights for dossiq-sensitive, `audit: true`, `reveal: "explicit"`
- **WHEN** Pieter opens Sanne's contact page, or an administrator lists persons
- **THEN** the BSN reads "*****7609" and no reveal is recorded
- **WHEN** Pieter presses "Tonen", which reads `GET .../{id}?_reveal=citizenServiceNumber`
- **THEN** he receives the number and one `property.revealed` row is written

#### Scenario: An oracle

- **WHEN** a reader asks `?citizenServiceNumber=123457609` or `_order[citizenServiceNumber]=asc`
- **THEN** the request is refused with 400
