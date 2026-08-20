# Mock Registers

## Problem
Provide self-contained mock registers for the five Dutch base registries -- BRP (persons), KVK (businesses), BAG (addresses/buildings), DSO (environmental permits), and ORI (council information) -- so that Procest, Pipelinq, and other consuming apps can develop and demonstrate integrations without external API credentials, government certificates, or network access. Each register ships as a `*_register.json` file in `lib/Settings/` following the OpenAPI 3.0.0 + `x-openregister` extension pattern, with seed data in the `components.objects[]` array using the `@self` envelope format, imported via the `ConfigurationService -> ImportHandler` pipeline.

## Proposed Solution
Extend the existing implementation with 10 additional requirements.
