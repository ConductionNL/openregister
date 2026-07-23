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
