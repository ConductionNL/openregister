# Mock Registers

## Problem
Provide self-contained mock registers for the five Dutch base registries -- BRP (persons), KVK (businesses), BAG (addresses/buildings), DSO (environmental permits), and ORI (council information) -- so that Procest, Pipelinq, and other consuming apps can develop and demonstrate integrations without external API credentials, government certificates, or network access. Each register ships as a `*_register.json` file in `lib/Settings/` following the OpenAPI 3.0.0 + `x-openregister` extension pattern, with seed data in the `components.objects[]` array using the `@self` envelope format, imported via the `ConfigurationService -> ImportHandler` pipeline.
This capability is a key competitive differentiator: competitor products (KISS, Dimpact ZAC, Open Formulieren) all require extensive external infrastructure to run locally. Our mock registers make the entire suite self-contained from `docker compose up`.

## Proposed Solution
Implement Mock Registers following the detailed specification. Key requirements include:
- Requirement: BRP Mock Register (Basisregistratie Personen)
- Requirement: KVK Mock Register (Kamer van Koophandel)
- Requirement: BAG Mock Register (Basisregistratie Adressen en Gebouwen)
- Requirement: DSO Mock Register (Digitaal Stelsel Omgevingswet)
- Requirement: ORI Mock Register (Open Raadsinformatie)

## Scope
This change covers all requirements defined in the mock-registers specification.

## Success Criteria
- Load BRP register from JSON file
- BSN validation on all seed persons
- Family unit cross-referencing
- Coverage of required demographic scenarios
- Address linking to BAG register
