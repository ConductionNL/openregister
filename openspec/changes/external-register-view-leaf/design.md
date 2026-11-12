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

## Open questions (Ruben)

`ExternalRegisterDegrade` is built and tested but has no caller: the provider (task 1.1) and the leaf (2.1, 2.2) it belongs to do not exist yet. Three answers are needed before they can be built.

1. **The response mapping in `x-openregister-object-source.config`.**
   - Proposed: reuse an existing OpenRegister `Mapping` by reference (`{"source": "bag", "mapping": "bag-adres-to-object", "find": "/adressen/{key}", "findAll": "/adressen"}`), so the mapping is administered where mappings already live and Twig/mapping tests cover it.
   - Options: (a) a Mapping reference, as proposed; (b) an inline JSON-pointer map in the schema config (`{"property": "/path/in/response"}`), simpler but a second mapping language; (c) no mapping, the source must answer in object shape.
2. **How the provider calls a named OpenConnector source.** `ExternalIntegrationRouter::call()` takes an `IntegrationProvider`, not a source name.
   - Proposed: `ExternalRegisterProvider` (task 2.1) is the `IntegrationProvider`, and the source id comes from the schema config, passed in the router's `$options`.
   - Options: (a) as proposed; (b) a router method that takes a source id directly; (c) one IntegrationProvider per register (BAG, BRK, WOZ), which D-2 argues against.
3. **The leaf endpoint and its degrade answer.** `find()` returns `?ObjectEntity`, which cannot carry the six degrade states.
   - Proposed: the widget reads `GET /api/external-register/{register}/{schema}/{key}`, answering `{state, adminActionable, record, fetchedAt, source}` from `ExternalRegisterDegrade::evaluate()`, with `cacheSecondsFor()` as its cache TTL. The object read path (`GET /api/objects/...`) keeps returning a plain 404 or 503.
   - Options: (a) as proposed; (b) the state rides the object read as a response header; (c) the provider throws typed exceptions that the object controller maps to the states.
