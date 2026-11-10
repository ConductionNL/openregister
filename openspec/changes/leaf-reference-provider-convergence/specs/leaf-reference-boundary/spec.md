## ADDED Requirements

### Requirement: The leaf and reference-provider mechanisms coexist as separate contracts

The OpenRegister data-provider leaf and the Nextcloud IReferenceProvider SHALL
remain separate, independently registered mechanisms. This decision introduces
no adapter, migration, or shared registration path between them, and app-local
read leaves MUST NOT be reimplemented as IReferenceProvider implementations.

The two are not redundant: a data-provider leaf is object-collection scoped with
an optional append, while an IReferenceProvider resolves a single read-only
preview from a string. Because those shapes do not map onto each other,
convergence is rejected and coexistence is the standing decision.

#### Scenario: A leaf is not required to become a reference provider

- **GIVEN** an app-local data-provider leaf that lists an object's notes
- **WHEN** the app registers it through RegisterLeafProvidersEvent
- **THEN** the leaf is valid as-is and is not required to be expressed as an IReferenceProvider

#### Scenario: No bridge adapter is introduced

- **GIVEN** this decision change
- **WHEN** its deliverable is inspected
- **THEN** no code is added that surfaces an IReferenceProvider as a leaf or a leaf as an IReferenceProvider

### Requirement: Stateless URL and text previews use IReferenceProvider

Authors MUST implement a stateless preview of a single URL or text token, one
that has no OpenRegister object and no collection, as an IReferenceProvider and
SHALL NOT implement it as a data-provider leaf.

A data-provider leaf keys on a register, schema, and object id and returns a
collection; using it for a bare URL preview that has none of those is a misuse
that reviewers MUST reject in favour of an IReferenceProvider.

#### Scenario: A bare URL preview is a reference provider

- **GIVEN** an app that wants to render a rich preview of a single external URL inside a chat message
- **WHEN** the author chooses a mechanism
- **THEN** an IReferenceProvider is used and a data-provider leaf is not

#### Scenario: A leaf misused for a stateless preview is rejected

- **GIVEN** a proposed data-provider leaf whose list ignores the object and only previews a URL
- **WHEN** it is reviewed
- **THEN** it is rejected and redirected to an IReferenceProvider

### Requirement: Object-scoped collections and appends use a data-provider leaf

Authors MUST implement an object-scoped collection of an app's own items on an
OpenRegister object, and any append of such an item, as a data-provider leaf and
SHALL NOT implement either as an IReferenceProvider.

The IReferenceProvider contract resolves a single read-only reference and offers
no object-collection scoping and no write, so it MUST NOT be stretched to carry
either; those cases belong to the leaf mechanism.

#### Scenario: Listing an object's notes is a leaf

- **GIVEN** an app that holds notes about an OpenRegister object in its own store
- **WHEN** the author chooses a mechanism to list and append those notes on the object
- **THEN** a data-provider leaf is used and an IReferenceProvider is not

#### Scenario: Append is never expressed as a reference

- **GIVEN** a requirement to append a note against an object
- **WHEN** the author picks a mechanism
- **THEN** the leaf create path is used because IReferenceProvider offers no write

### Requirement: The single-entity render overlap resolves by context

Authors MUST resolve the one overlap between the mechanisms, rendering a single
read-only linked entity identified by a URL or id, by context, and SHALL NOT
implement it twice for the same surface.

When the entity hangs off an OpenRegister object as that object's linked-entity
render, it is the leaf single-entity surface; when it is an inline preview of a
bare URL in free text, it is an IReferenceProvider. A future bridge that lets a
reference also render as the leaf single-entity surface is a named but unadopted
option and SHALL NOT be assumed to exist.

#### Scenario: Object-anchored single entity is a leaf surface

- **GIVEN** a schema property that references one entity in a sibling app
- **WHEN** that entity is rendered as a card on the object detail page
- **THEN** the leaf single-entity render surface is used

#### Scenario: Inline URL preview is a reference

- **GIVEN** a bare URL to that same entity pasted into a text document
- **WHEN** it is previewed inline
- **THEN** an IReferenceProvider resolves it and no leaf is involved

@e2e exclude Decision-and-boundary change — no runtime behaviour ships; the rule is enforced by review, not an executable path
