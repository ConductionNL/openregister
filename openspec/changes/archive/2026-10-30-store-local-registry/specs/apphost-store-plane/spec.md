# apphost-store-plane: delta for store-local-registry

## ADDED Requirements

### Requirement: A store can name this instance as its registry

A store whose `registry_url` is `local` SHALL be read from the configured register on this instance, through the object service, without an HTTP request. A register or schema that cannot be read SHALL answer `store_unreachable`.

#### Scenario: a local catalogue

- GIVEN pipelinq's `registry_url` is `local` and its register holds nine `commercial-template` objects
- WHEN the store page searches
- THEN the nine items are returned as cards
- AND no HTTP request is made

#### Scenario: the schema is missing

- GIVEN `registry_url` is `local` and the register has no `commercial-template` schema
- WHEN the store page searches
- THEN the outcome is `store_unreachable` and there are no cards
