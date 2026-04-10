## Why

Dutch government organisations are legally required to manage retention periods (bewaartermijnen) and destruction of records per the Archiefwet 1995. OpenRegister already has a basic `retention` field on ObjectEntity and an `archive` property on Schema, but lacks the actual lifecycle management: selectielijsten configuration, automated destruction scheduling, multi-step approval workflows, legal holds, and e-Depot transfer. Market intelligence shows 77% of government tenders require archiving and destruction capabilities, making this the most demanded missing feature.

## What Changes

- Add MDTO-compliant archival metadata fields to the object `retention` property (archiefnominatie, archiefactiedatum, archiefstatus, classificatie, bewaartermijn)
- Implement selectielijsten as configurable register objects mapping object types to retention periods and archival actions
- Add configurable afleidingswijzen (derivation methods) for calculating archiefactiedatum from various source dates
- Create a `DestructionCheckJob` background job that generates destruction lists from objects past their archiefactiedatum
- Implement multi-step destruction approval workflow with archivist roles, partial rejection, and two-step approval for sensitive schemas
- Add legal hold (bevriezing) support to exempt objects from destruction processes
- Generate destruction certificates (verklaring van vernietiging) as immutable archival records
- Add pre-destruction notification system using Nextcloud INotification
- Implement e-Depot export via SIP packages with MDTO XML metadata
- Add cascading destruction rules that integrate with existing referential integrity
- Handle WOO-published objects with extended retention and explicit destruction confirmation
- Extend retention settings API with archival configuration endpoints

## Capabilities

### New Capabilities
- `retention-management`: Core retention lifecycle — MDTO metadata on objects, selectielijsten configuration, archiefactiedatum calculation via afleidingswijzen, automated destruction scheduling via background jobs, multi-step approval workflows, legal holds, destruction certificates, pre-destruction notifications, and retention settings API

### Modified Capabilities
- `archivering-vernietiging`: Extends the existing archiving/destruction spec with concrete implementation details for e-Depot SIP export, cascading destruction with referential integrity, and WOO publication interaction rules

## Impact

- **Database**: New columns/JSON fields on ObjectEntity.retention (archiefnominatie, archiefstatus, archiefactiedatum, classificatie, bewaartermijn, legalHold); Schema.archive extended with default retention config
- **API**: New endpoints for selectielijst CRUD, destruction list management, legal hold operations, e-Depot configuration, retention settings; existing object endpoints expose archival metadata
- **Background Jobs**: New `DestructionCheckJob` (TimedJob) and `DestructionExecutionJob` (QueuedJob) for scheduled processing
- **Services**: New RetentionService, DestructionService, SelectielijstService; extends ObjectRetentionHandler
- **Notifications**: INotification integration for pre-destruction warnings and destruction list review requests
- **Dependencies**: Impacts opencatalogi (objects carry retention metadata in search/listing), docudesk (PDF/A renditions for SIP packages)
- **Migrations**: Database migration for extended retention JSON structure
