# The store exchanges configuration, not rows

## Problem

OpenRegister has two stores. They were built for the same purpose, neither
references the other, and the weaker one is the one users can see.

**`federated-config-sharing`** is the fleet standard. A schema opts itself in
with one marker, `x-openregister-shareable`, and `SchemaShareableConfigScanner`
turns it into a shareable type without any per-app code. Three types ship
built in: flows, registers and schemas, and a whole configuration set, which
`ConfigSetShareableConfigType` documents as "an app's worth of configuration at
once: registers, schemas, objects, views, flows, sources and mappings". Bundles
are signed with the instance's Ed25519 key, published to a repository, found by
topic, and gated on a per-org trusted-key list. The spec calls this the store:
"A schema SHALL be able to opt its objects into the store with a single marker."

Twenty one of its twenty five tasks are done. The backend works. It has no user
interface at all, so nobody can reach it.

**`apphost-store-plane`** is what users actually see. `CnStorePage` calls
`/api/store/items`, the engine fetches items from one remote OpenRegister
instance over HTTP, and `GenericStoreInstaller` writes each component of an item
as a plain object into one allowlisted schema. It cannot carry a schema. It
cannot carry a flow. It reads its allowlist from the consuming app's manifest,
so the schema never gets to say whether it may travel.

The result is a Store menu entry that exchanges rows of a single schema. A
municipality cannot publish the way it runs its council. A neighbouring
municipality cannot install it.

## What a store item should be

A store item is a collection of schemas and flows that work together to provide
a functionality. Decidiq publishes a default gemeente: the organisational
structure, the decision types, and the flows that move a decision through them.
Dossiq publishes case types. Portaliq publishes forms. Buildiq publishes whole
apps. Humaniq publishes tax schemas. Shillinq publishes administration setups.

Each of those is one shape, not six. `ConfigSetShareableConfigType` already is
that shape.

**The fleet has already written these, and calls them seed datasets.** Decidiq
ships four in `lib/Settings/profiles/`: a municipality with committees and
factions, an association with a members' meeting and a board, a company board
with a supervisory and an executive layer, and a works council. Every one is a
named organisational structure with the schemas and vocabulary to run it, which
is a configuration set with a different file extension. `SeedProfileService`
already imports one on request.

So the first store catalogue is not something anyone has to invent. It is the
seed data each app already ships, published instead of bundled.

## Solution

Point the store surface at the engine that already does this, and delete the
duplicate exchange rather than grow it.

- **`GenericStoreController::search()` sources its cards from
  `FederatedConfigService::discover()`**, across the topics of the shareable
  types the calling app declares, instead of from one remote objects API.
- **`install()` resolves the card and calls `FederatedConfigService::install()`**,
  which routes to the owning type's `deserialise()`. A config set arrives as
  registers, schemas, objects, views, flows, sources and mappings. A flow
  arrives as a flow.
- **The schema decides whether it travels.** `x-openregister-shareable` is
  already read by the scanner, so an app that marks a schema gets it in the
  store with no manifest change. The manifest `installable` allowlist stays as
  the second boundary, because a remote publisher naming a schema is not the
  same claim as that schema being shareable.
- **Trust moves with it.** A federated install already passes
  `isSourceAllowed()` and the trusted-key check, which the object install path
  has no equivalent for.

The manifest `store` block gains `types`, the shareable type ids an app
surfaces. `schema`, `register` and `cardFields` stay, and an app that declares
only those keeps the object store it has today, so nothing that ships now
breaks while the fleet moves.

## Affected Projects

- [x] Project: `openregister` — the engine and the store controller.
- [x] Project: `nextcloud-vue` — `CnStorePage` renders a type and a publisher.
- [x] Project: `decidiq` — first consumer, publishes a default gemeente.

## Out of scope

Retiring the object store path. It stays until every app that uses it has
moved, and this change does not move them.

Dossiq's hand-written `StoreController`, which predates both engines and wins
the route alias by design. It moves in its own change.
