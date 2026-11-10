## ADDED Requirements

### Requirement: List page size is bounded by a hard maximum

Every object list/search endpoint SHALL clamp the effective page size to a hard
maximum. A client-supplied `_limit` above the maximum SHALL be reduced to the
maximum; it SHALL NOT cause the server to load an arbitrarily large result set.

#### Scenario: Oversized limit is clamped

- **WHEN** a client requests a list with `_limit` far above the maximum (e.g.
  `_limit=1000000`)
- **THEN** at most `MAX_PAGE_SIZE` rows are loaded and returned

### Requirement: The total-count query is optional

A client SHALL be able to request a list without the total count. When the total
is opted out, the endpoint SHALL NOT execute the COUNT query and SHALL return
`total: null`.

#### Scenario: Count can be skipped

- **WHEN** a client requests a list with `_count=false`
- **THEN** no COUNT query is executed
- **AND** the response reports `total: null`

#### Scenario: Default behaviour includes the total

- **WHEN** a client requests a list without the count flag
- **THEN** the total is computed and returned as before
