## ADDED Requirements

### Requirement: A store MUST be able to offer configuration, not only objects

A leaf app SHALL be able to declare `store.types`, a list of shareable
configuration type ids. When it does, the store surface SHALL list what
`FederatedConfigService::discover()` returns for each declared type's topic,
and each card SHALL carry the type id that produced it.

A store item found this way MAY contain registers, schemas, objects, views,
flows, sources and mappings together, because that is what a configuration set
is. The plane SHALL NOT flatten such an item into objects of one schema.

#### Scenario: A configuration set reaches the store surface

- **GIVEN** an app declaring `store.types: ["openregister.config-set"]`
- **AND** a published configuration set tagged with that type's topic
- **WHEN** an administrator opens the app's store
- **THEN** the set is listed as one card naming its type and publisher

#### Scenario: An app that declares no types keeps the object store

- **GIVEN** an app declaring only `store.schema` and `store.register`
- **WHEN** the store is searched
- **THEN** the remote objects API is used exactly as before
- **AND** no discovery call is made

### Requirement: A schema MUST decide whether it may be shared

A schema carrying `x-openregister-shareable` in its configuration SHALL be
offered as a shareable type by `SchemaShareableConfigScanner`, and the store
surface SHALL list it for any app that declares its type id.

A schema without that marker SHALL NOT be offered, even when the app's
`installable` allowlist names it. The marker governs whether the schema may
travel at all, which is a different question from what an install may write.

The `installable` allowlist SHALL NOT gate a configuration install, and this is
deliberate. A configuration set exists to introduce registers, schemas and
flows the instance does not have yet, so a list of schemas the app already owns
cannot express whether that set may be applied: it would refuse exactly the
sets worth installing. The trust boundary for a bundle is its PUBLISHER, and it
is enforced by `isSourceAllowed()` and the trusted-key check. The allowlist
keeps its meaning for the objects path, where an item names a schema the app
does own.

#### Scenario: A set may introduce a schema the app does not own

- **GIVEN** a configuration set carrying a schema absent from `installable`
- **AND** a publisher this organisation trusts
- **WHEN** an administrator installs it
- **THEN** the schema is created

#### Scenario: An unmarked schema is not offered

- **GIVEN** a schema absent `x-openregister-shareable`
- **AND** an app whose `installable` list names that schema
- **WHEN** the store is searched
- **THEN** the schema is not listed as a shareable type

### Requirement: A configuration install MUST run through its owning type

Installing a card that carries a type id SHALL fetch the bundle and call
`FederatedConfigService::install()`, which routes to that type's
`deserialise()`. The plane SHALL NOT write the bundle's contents directly.

The install SHALL refuse a source that `isSourceAllowed()` rejects, and SHALL
report the refusal rather than installing part of the bundle.

#### Scenario: An untrusted publisher is refused

- **GIVEN** a card published by a source outside the org allowlist
- **WHEN** an administrator installs it
- **THEN** the install is refused and names the source
- **AND** nothing is written

#### Scenario: A flow arrives as a flow

- **GIVEN** a published bundle of type `openregister.flows`
- **WHEN** an administrator installs it
- **THEN** the flow exists on this instance
- **AND** it is not written as an object of some other schema

## MODIFIED Requirements

### Requirement: A store descriptor MUST carry every per-app parameter

The descriptor SHALL carry the app id, the remote schema slug, the default
register and the card-field map, so that everything differing between apps is
data and everything shared lives once in `GenericStoreService`.

It SHALL additionally carry the declared shareable type ids. A descriptor with
type ids selects federated discovery; a descriptor with none keeps the remote
objects API, so an app that has not moved is unaffected.

#### Scenario: A descriptor names its types

- **GIVEN** a manifest declaring two shareable type ids
- **WHEN** the descriptor is built
- **THEN** it carries both ids in declaration order
