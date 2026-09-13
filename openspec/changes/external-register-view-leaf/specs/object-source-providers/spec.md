# object-source-providers

## ADDED Requirements

### Requirement: A generic HTTP object-source provider maps a configured source

The system SHALL ship an object-source provider `openconnector-http` that
reads a schema's objects from a named OpenConnector source through the
integration router, mapping the response with a declared mapping in
`x-openregister-object-source.config`, answering `find()` by key and
`findAll()` by the declared query parameters, read-only, RBAC-scoped and
degrading to an explained empty result when the source is unavailable.

#### Scenario: a BAG address is served as an object

- **GIVEN** the seeded `bag-adres` schema bound to a configured BAG source
- **WHEN** `GET /api/objects/{register}/bag-adres/0363200000006110` is called
- **THEN** the mapped address is returned as a non-persisted object
- @e2e exclude {backend provider, verified with a stubbed source in unit tests}

#### Scenario: a missing source degrades

- **GIVEN** the same schema and no configured source
- **WHEN** the object is requested
- **THEN** the response is an explained unavailable result, not a 500
- @e2e exclude {degrade contract, covered by unit tests}

### Requirement: An external register record renders on another object

The system SHALL offer an `external-register` leaf with `widget` and `tab`
surfaces whose placement names a sourced schema, the host property holding
the key and the fields to display. The surface SHALL read the record
through the object source read path, SHALL show when and from where it was
fetched, SHALL offer a refresh, SHALL never copy the record into the host
object, and SHALL render an explained empty state when the provider is
absent or the key is empty.

#### Scenario: a case shows its BAG address

- **GIVEN** a case with `address.identifier` set and a widget placement on `bag-adres` keyed by it
- **WHEN** a handler opens the case
- **THEN** the widget shows the address fields and the fetch time
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/external-register-leaf.spec.ts when the surface ships}

#### Scenario: an empty key shows nothing and explains

- **GIVEN** a case without `address.identifier`
- **WHEN** the widget renders
- **THEN** it shows an empty state naming the missing property
- @e2e exclude {empty state, covered by vitest on the widget}
