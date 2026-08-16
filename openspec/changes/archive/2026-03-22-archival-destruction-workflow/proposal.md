## Why

Dutch government organisations using OpenRegister must comply with the Archiefwet 1995, Archiefbesluit 1995, and NEN 15489 (records management) for automated archival destruction of register objects. Currently, OpenRegister has retention metadata fields (`ObjectEntity.retention`, `ObjectRetentionHandler`, `Schema.archive`) and a comprehensive draft spec (`archivering-vernietiging`), but no background jobs for destruction scheduling, no destruction list workflow, no legal hold enforcement, and no destruction certificate generation. 77% of analyzed government tenders require these capabilities, making this a critical gap for municipal adoption.

## What Changes

- Add `DestructionCheckJob` background job that scans for objects past their `archiefactiedatum` with `archiefnominatie=vernietigen` and generates destruction lists
- Add `DestructionExecutionJob` queued job that permanently deletes approved objects in batches, including associated Nextcloud Files
- Add destruction list API endpoints for creating, reviewing, approving (full/partial), and rejecting destruction lists
- Add legal hold API endpoints for placing, releasing, and querying legal holds on objects and schemas
- Add destruction certificate generation (verklaring van vernietiging) upon completed destruction
- Add archiefactiedatum calculation service supporting multiple afleidingswijzen (afgehandeld, eigenschap, termijn)
- Extend `ObjectRetentionHandler` with legal hold checks and destruction eligibility validation
- Add two-step approval workflow for sensitive schemas
- Integrate with audit trail for all destruction and legal hold operations (`archival.destroyed`, `archival.legal_hold_placed`, etc.)

## Capabilities

### New Capabilities
- `archival-destruction-workflow`: Destruction list generation, multi-step approval workflow, batch execution, destruction certificates, and legal hold management

### Modified Capabilities
- `archivering-vernietiging`: Implementing the draft spec requirements -- adding archiefactiedatum calculation, selectielijst integration, cascading destruction rules, WOO-published object handling, and e-Depot transfer preparation hooks

## Impact

- **Backend**: New background jobs (`DestructionCheckJob`, `DestructionExecutionJob`), new service classes (`DestructionService`, `LegalHoldService`, `ArchiefactiedatumCalculator`), new API controller methods on `ObjectsController` or a dedicated `ArchivalController`
- **Database**: No schema migration needed -- destruction lists and legal holds are stored as register objects using existing `ObjectEntity` infrastructure; retention metadata already exists on `ObjectEntity`
- **Dependencies**: Integrates with `audit-trail-immutable` (audit entries for all operations), `deletion-audit-trail` (soft delete before permanent destruction), `referential-integrity` (cascade handling)
- **Dependent apps**: OpenCatalogi and SoftwareCatalog objects with archival metadata will be subject to destruction workflows -- no code changes needed in those apps as this is register-level behavior
- **API surface**: New endpoints under `/api/archival/` for destruction lists and legal holds; existing object endpoints gain `retention.legalHold` field support
