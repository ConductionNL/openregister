# Proposal: retention-management

## Why

Dutch government organisations are legally required to manage retention periods (bewaartermijnen) and destruction of records per the Archiefwet 1995. OpenRegister already has a basic `retention` field on `ObjectEntity`, an `archive` block on `Schema`, and an `AvgRetentionJob` background job, but lacks the configurable lifecycle management that municipalities expect: selectielijsten configuration, derived archiefactiedatum calculation, multi-step destruction approval, legal holds, and a retention dashboard. Market intelligence shows 154+ tenders require retention management and 189 tenders require the destruction workflow that depends on it; this proposal adds the missing per-schema/per-register configuration surface and the operational tooling around the existing job.

## What Changes

- Add MDTO-aligned archival metadata fields to the object `retention` JSON (`archiefnominatie`, `archiefactiedatum`, `archiefstatus`, `bewaartermijn`, `vernietigingsdatum`)
- Add per-schema and per-register retention configuration with inheritance (register defaults, schema overrides) and resultaattype-aware periods
- Add configurable retention start triggers (creation date, modification date, closure date, custom field) with automatic archiefactiedatum calculation
- Add a retention dashboard listing upcoming expirations, overdue items, and held items grouped by register/schema
- Add manual override endpoints so authorised users can adjust retention period and destruction date per object
- Add VNG Selectielijst category mapping to schemas/registers so resultaattypen drive retention
- Extend the existing `AvgRetentionJob` to flag (not destroy) objects past their retention date for review
- Document integration with the shipped `archival-destruction-workflow` so destruction execution stays out of scope

## Capabilities

### Modified Capabilities
- `retention-management`: Adds per-schema configuration surface, retention dashboard, manual overrides, selectielijst mapping, and start-trigger derivation on top of the existing MDTO metadata model

## Summary

Implement configurable retention period management for OpenRegister objects, enabling organisations to assign, track, and enforce retention schedules (bewaartermijnen) per object type, schema, or register. Includes automatic flagging when retention expires and integration with selectielijsten from the VNG.

## Demand Evidence

**Cluster: Retention period management** -- 154 tenders, 425 requirements
**Related cluster: Archival destruction** -- 189 tenders (retention is a prerequisite)
**Combined unique demand**: 154+ tenders explicitly requiring retention management

### Sample Requirements from Tenders

1. **Gemeente Berkelland**: "Het is mogelijk om een bewaartermijn toe te kennen aan zaken."
2. **Gemeente Berkelland**: "Archivering vindt na afhandeling van een zaak in principe automatisch plaats o.b.v. bij het betreffende zaak- en resultaattype vastgelegde metadata ten behoeve van archivering (o.a. bewaartermijn)."
3. **Gemeente Berkelland**: "Gebruikers met de juiste autorisaties kunnen altijd handmatig gegevens zoals bewaartermijn en vernietigingsdatum aanpassen."
4. **Gemeente Hilversum**: "Met de Oplossing is het mogelijk om na het verstrijken van de bewaartermijn zaken, bestanden en metadata op een rechtmatige manier te vernietigen en levert hiervan een audittrail op."
5. **Gemeente Waalwijk**: "Per werkproces of zaaktype wordt de bewaartermijnen ingericht zoals vastgelegd in de Selectielijst archiefbescheiden gemeenten (VNG). Dit gebeurt op basis van resultaat (resultaattypen) van een proces."

## Scope

### In Scope

- **Retention period configuration**: Define retention periods per schema, register, or object type (in days, months, or years)
- **Retention start triggers**: Configure what event starts the retention clock (creation date, modification date, closure date, custom field)
- **Selectielijst integration**: Map VNG Selectielijst categories to schemas/registers, with support for different resultaattypen having different retention periods
- **Retention calculation engine**: Automatically calculate destruction dates based on configured retention periods and trigger events
- **Expiration flagging**: Background job that identifies objects past their retention date and flags them for review
- **Retention dashboard**: Overview of retention status across registers -- upcoming expirations, overdue items, held items
- **Manual override**: Authorised users can manually adjust retention periods and destruction dates for individual objects
- **Retention metadata fields**: Add `bewaartermijn`, `vernietigingsdatum`, `archiefactiedatum`, and `archiefnominatie` to object metadata

### Out of Scope

- Actual destruction execution (separate change: `archival-destruction-workflow`)
- e-Depot transfer (separate change: `edepot-transfer`)
- CSV import/export (already exists)

## Acceptance Criteria

1. Retention periods can be configured per schema with support for different periods based on result type
2. The system automatically calculates destruction dates when objects are created or their status changes
3. A background job runs periodically to flag objects that have passed their retention date
4. Authorised users can manually adjust retention periods and destruction dates
5. A retention dashboard shows upcoming expirations grouped by schema/register
6. VNG Selectielijst categories can be mapped to schema types
7. Retention metadata fields are stored as first-class object metadata (not custom properties)
8. Retention configuration supports inheritance: register-level defaults that schemas can override

## Dependencies

- OpenRegister Schema and Register entities for configuration storage
- OpenRegister ObjectService for metadata management
- Nextcloud BackgroundJob for periodic expiration checks
- No external service dependencies

## Standards & Regulations

- Archiefwet 1995 (Article 5: retention obligations)
- VNG Selectielijst archiefbescheiden gemeenten en intergemeentelijke organen
- NEN-ISO 15489-1:2016 (records management)
- RGBZ (Referentiemodel Gemeentelijke Basisgegevens Zaken) for resultaattypen

## Notes

- This change is a foundational prerequisite for `archival-destruction-workflow` -- retention periods must be defined before destruction can be triggered
- OpenRegister already has CSV import/export with ID support
