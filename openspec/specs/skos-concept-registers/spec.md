---
status: done
---

# skos-concept-registers Specification

## Purpose

@e2e exclude backend vocabulary registers + import + resolution API — covered by PHPUnit (leaf-side consumption UIs ship their own e2e in the leaf repos).

OpenRegister ships canonical SKOS `conceptScheme` + `concept` schemas in a bundled `vocabulary` register, an idempotent URI-keyed SKOS/CSV importer, bundled TOOI Woo value-list seeds (17 informatiecategorieën + documenthandelingen), and a public concept-resolution API. Vocabularies are register data and are OpenRegister-owned (ADR-022); leaf apps (opencatalogi TOOI/DCAT, softwarecatalog GEMMA, decidesk ORI) consume this capability. Introduced by change `skos-concept-registers` (2026-07-23), driven by NL-SBB becoming mandatory for Federatief Datastelsel participants per 1 Jan 2026.

## Requirements

### Requirement: Canonical conceptScheme and concept schemas (SKOS-001)

OpenRegister MUST ship canonical `conceptScheme` and `concept` schemas in a
vocabulary register definition. `conceptScheme` MUST carry at minimum: `uri`
(unique, required), `title`, `publisher`, `version`, `source` (where the
scheme was imported from). `concept` MUST carry at minimum: `uri` (unique
within the register, required), `prefLabel` (multilingual object keyed by
BCP-47 language tag, `nl` required), `altLabel` (multilingual, optional),
`definition`, `notation`, `inScheme` (relation to a `conceptScheme` in the
canonical relation dialect), and `broader`/`narrower`/`related` relations to
sibling concepts. Every property MUST carry a human-friendly English `title`
and `description` (schema-property-titles gate). Reads MUST be public;
writes MUST be admin-gated.

#### Scenario: concept round-trips with hierarchy intact

- GIVEN a scheme with concepts A (broader of B) and B,
- WHEN B is fetched via the objects API,
- THEN B's `broader` MUST resolve to A per the canonical relation dialect,
- AND A's `narrower` MUST list B,
- AND B's `inScheme` MUST resolve to the scheme object.

> @e2e exclude Backend schema/relation contract; covered by PHPUnit against the seeded register.

### Requirement: Idempotent SKOS import keyed on URI (SKOS-002)

The system MUST provide an import service that ingests a concept scheme from
a SKOS serialization (Turtle, RDF/XML or JSON-LD) or a CSV value-list and
upserts concepts **keyed on their URI**: re-importing the same source MUST
NOT create duplicates, MUST update changed labels/definitions in place, and
MUST report counts (created/updated/unchanged). Concepts present in the
register but absent from the re-imported source MUST be flagged (deprecated
marker), never hard-deleted, so leaf references cannot dangle.

#### Scenario: re-import is a no-op on unchanged source

- GIVEN a scheme imported from a TOOI value list,
- WHEN the identical source is imported again,
- THEN the report MUST show 0 created, 0 updated,
- AND the concept count in the register MUST be unchanged.

> @e2e exclude Backend import contract; PHPUnit with fixture files.

#### Scenario: removed concept is deprecated, not deleted

- GIVEN a scheme whose re-imported source no longer contains concept X,
- WHEN the import completes,
- THEN X MUST remain retrievable with a deprecated marker set,
- AND resolution of X by URI MUST still succeed.

> @e2e exclude Backend import contract; PHPUnit.

### Requirement: Bundled TOOI seed vocabularies (SKOS-003)

OpenRegister MUST bundle the Woo-critical TOOI value lists
(informatiecategorieën, documentsoorten, and the overheidsorganisatie scheme
identifiers) as import fixtures and seed them into the vocabulary register on
install/repair, so a fresh instance can serve DiWoo/DCAT vocabulary lookups
without network access. Seeding MUST reuse the idempotent importer (SKOS-002)
and therefore MUST be safe to run repeatedly.

#### Scenario: fresh install serves the 17 informatiecategorieën

- GIVEN a fresh install after the repair step ran,
- WHEN the informatiecategorie scheme's concepts are listed,
- THEN all 17 Woo informatiecategorieën MUST be present with TOOI URIs and
  Dutch prefLabels.

> @e2e exclude Install/repair backend contract; PHPUnit + existing repair-step test harness.

### Requirement: Concept resolution API (SKOS-004)

The system MUST expose public read endpoints to (a) resolve a single concept
by exact URI, (b) resolve by `(scheme, notation)` pair, and (c) list a
scheme's concepts with paginated label search (matching `prefLabel`/`altLabel`
in any language). Responses MUST use the standard objects envelope. Unknown
URIs MUST return HTTP 404 with the standard error shape, never an empty 200.

#### Scenario: resolve by URI

- GIVEN the seeded TOOI informatiecategorie scheme,
- WHEN a concept is requested by its exact TOOI URI,
- THEN the concept object MUST be returned with prefLabel and inScheme.

> @e2e exclude Public API backend contract; covered by PHPUnit controller tests (leaf-side consumption UIs ship their own e2e in the leaf repos).

#### Scenario: label search within a scheme

- GIVEN the seeded scheme,
- WHEN concepts are listed with a label query matching one concept's Dutch prefLabel,
- THEN exactly the matching concepts MUST be returned in the standard
  paginated envelope.

> @e2e exclude Same backend contract; PHPUnit.

### Requirement: A concept carries its own fields and its own validity window (REQ-CLH-001)

A concept scheme MAY declare the shape of its concepts, and concepts SHALL
then be validated against that shape on import and on save. A concept MAY
carry `validFrom` and `validUntil`. Outside its window a concept SHALL NOT
be offered as an option and SHALL still resolve on read with its label, so
objects that already hold it stay readable. A write of an out-of-window
concept SHALL be refused with 422 naming the concept and the window. A
scheme MAY declare an exclusive group, and an object holding two concepts
of one group SHALL be refused.

#### Scenario: a retired value keeps working on old records

- **GIVEN** a concept whose `validUntil` has passed and an object saved with it last year
- **WHEN** the object is read and the property's options are read
- **THEN** the object still resolves the concept with its label
- **AND** the options do not offer it

#### Scenario: a retired value cannot be written today

- **GIVEN** the same concept
- **WHEN** a new object is saved with it
- **THEN** the save fails with 422 naming the concept and its window

#### Scenario: a list item carries its own fields

- **GIVEN** a scheme declaring concepts with `bewaartermijn` and `grondslag`
- **WHEN** a concept is imported without `grondslag`
- **THEN** the import reports that concept as invalid, naming `grondslag`
- @e2e exclude {importer, covered by unit tests}

#### Scenario: two concepts of one exclusive group are refused

- **GIVEN** a scheme with concepts `spoed` and `regulier` in one exclusive group
- **WHEN** an object is saved holding both
- **THEN** the save fails with 422 naming the group and both concepts

### Requirement: A property uses the hierarchy, the context and the weights (REQ-CLH-002)

A coded property MAY declare a branch of the scheme as its source and MAY
require a leaf concept, and its options SHALL then be returned as a tree.
A filter on a branch SHALL match objects holding any narrower concept,
resolved at query time with a bounded depth. A coded property MAY bind its
option subset to the value of another property or to a declared context
key. A concept MAY carry a weight, and a multi-valued coded property MAY
declare a score rolled up from the weights of the concepts it holds,
evaluated by the calculation engine.

#### Scenario: filtering by a branch finds the leaves

- **GIVEN** a scheme where `vergunning` is broader of `kapvergunning` and objects holding the narrower concept
- **WHEN** the object list is filtered on the branch `vergunning`
- **THEN** the objects holding `kapvergunning` are returned

#### Scenario: one property serves two case types with different values

- **GIVEN** a property whose option subset is bound to the value of `zaaktype`
- **WHEN** the options are read for `zaaktype: bezwaar` and for `zaaktype: melding`
- **THEN** each read returns only its own subset

#### Scenario: a leaf rule refuses a broader value

- **GIVEN** a property declaring leaf concepts only
- **WHEN** an object is saved with a concept that has narrower concepts
- **THEN** the save fails with 422 naming the concept
- @e2e exclude {validator, covered by unit tests}

#### Scenario: the weights roll up to a score

- **GIVEN** a multi-valued coded property with weights 3 and 5 on the concepts held, and a declared rolled-up score
- **WHEN** the object is read
- **THEN** the score reads 8
- @e2e exclude {calculation engine, covered by unit tests}

### Requirement: A code-list value is retired, never deleted out from under its records (REQ-CLH-006)

A concept the product itself defines SHALL NOT be deletable, and a concept
any object still holds SHALL NOT be deletable. Both refusals SHALL name the
validity window as the way to retire the value instead, and the second SHALL
name how many objects hold it. A concept nothing holds, and that the product
does not define, SHALL stay deletable.

#### Scenario: a system-defined value cannot be deleted

- **GIVEN** a concept marked as system-defined
- **WHEN** it is deleted through the objects API
- **THEN** the delete is refused and the message points at the validity window

#### Scenario: a value in use cannot be deleted, and the refusal names the count

- **GIVEN** a concept and objects holding it
- **WHEN** it is deleted through the objects API
- **THEN** the delete is refused naming how many objects hold it

#### Scenario: a value nothing holds stays deletable

- **GIVEN** a concept no object holds and the product does not define
- **WHEN** it is deleted
- **THEN** the delete succeeds
- @e2e exclude {counting, covered by ConceptDeleteGuard unit tests}
