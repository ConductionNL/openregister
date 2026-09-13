# Design: external-register-view-leaf

## D-1: a sourced schema, not a bespoke widget

The row could be met with three widgets that call three lookups. That is
what dossiq has today and it is what the register calls a dossiq page. A
sourced schema makes the record an object: queryable, RBAC-scoped,
projectable into aggregations, and rendered by one surface for every
register.

## D-2: one generic HTTP provider, declared mappings

BAG, BRK and WOZ differ in URL and shape, not in mechanism. One provider
takes an OpenConnector source and a response mapping from the schema's
`x-openregister-object-source.config` and answers `find()` and `findAll()`
by key. A fourth register is a schema file, not code.

## D-3: keyed by a host property

The placement names the host property whose value is the key. The surface
reads it from the host object it is mounted on (the leaf contract), so it
works on any schema that has an address, a parcel or a KvK number.

## D-4: degrade, never fail

A source without credentials, a provider app that is disabled, or a
lookup that times out renders an explained empty state (the provider's
degrade contract). A case page never breaks because a basisregistratie is
down.

## D-5: kind

Code, in OpenRegister. integriq configures sources; dossiq places widgets.
