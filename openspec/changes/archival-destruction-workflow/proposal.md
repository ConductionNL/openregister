<<<<<<< HEAD
---
status: approved
---

# Archival & Destruction Workflow

## Problem
Government organizations using OpenRegister need to comply with Dutch archival legislation (Archiefwet 1995) and records management standards (NEN 2082, MDTO). Currently, there is no mechanism to:
1. Track archival metadata (archiefnominatie, archiefactiedatum, archiefstatus) on objects
2. Configure retention schedules via selection lists (selectielijsten)
3. Generate and approve destruction lists for objects past their retention period
4. Run automated background checks for objects due for destruction

77% of analyzed government tenders require these capabilities.

## Proposed Solution
Implement a phased archival and destruction workflow:

**Phase 1 (this change):**
- Archival metadata service to manage retention data on objects via the existing `retention` JSON field
- Selection list (selectielijst) entity and CRUD for configuring retention rules
- Destruction list entity with approval workflow (generate, review, approve/reject)
- Background job to scan for objects due for destruction
- API endpoints for all archival operations
- Audit trail integration for destruction actions

**Future phases:**
- e-Depot export (SIP/MDTO XML generation)
- NEN 2082 compliance reporting
- Integration with external archival systems

## Impact
- New entities: `SelectionList`, `SelectionListMapper`, `DestructionList`, `DestructionListMapper`
- New service: `ArchivalService`
- New controller: `ArchivalController`
- New background job: `DestructionCheckJob`
- Leverages existing: `ObjectEntity.retention` field, `AuditTrailMapper`, `ObjectService`

## Risks
- The `retention` field on ObjectEntity is currently unused; we must ensure backward compatibility
- Destruction is irreversible; the approval workflow is critical for safety
- Selection list configuration must be flexible enough for different government contexts
=======
# Proposal: archival-destruction-workflow

## Summary

Implement a NEN 15489 compliant archival destruction workflow for register objects, including selectielijst management, approval-based destruction with audit trail, and referential integrity checks. This enables Dutch government organisations to lawfully destroy records after retention periods expire, conforming to Archiefwet 1995 and related regulations.

## Demand Evidence

**Cluster: Archival destruction** -- 189 tenders, 685 requirements
**Cluster: Selectielijst (archival schedule)** -- 38 tenders, 91 requirements
**Combined**: 227 tenders, 776 requirements across Dutch government procurement

### Sample Requirements from Tenders

1. **Gemeente Berkelland**: "Het is mogelijk om volgens geldende wet- en regelgeving documenten, zaken en bijbehorende metadata te vernietigen nadat de bewaartermijn is verstreken."
2. **Gemeente Berkelland**: "De Oplossing kan overzichten genereren van te vernietigen zaken."
3. **Gemeente Hilversum**: "Met de Oplossing is het mogelijk om na het verstrijken van de bewaartermijn zaken, bestanden en metadata op een rechtmatige manier te vernietigen en levert hiervan een audittrail op van de vernietiging."
4. **Gemeente Hilversum**: "De Oplossing controleert of de vernietigingstermijn van een zaak niet strijdig is met de vernietigingstermijn van gerelateerde zaken (referentiele integriteit)."
5. **Gemeente Waalwijk**: "De aangeboden oplossing ondersteunt een procedure/proces voor de rechtmatige vernietiging van gegevens en bestanden. Waarbij de aangeboden oplossing aangeeft welke informatie voor vernietiging in aanmerking komt."
6. **Gemeente Waalwijk**: "Per werkproces of zaaktype wordt de bewaartermijnen ingericht zoals vastgelegd in de Selectielijst archiefbescheiden gemeenten (VNG)."

## Scope

### In Scope

- **Destruction workflow engine**: Multi-step approval process for object destruction (propose, review, approve, execute)
- **Selectielijst management**: Import and manage VNG Selectielijst archiefbescheiden with retention categories linked to schema/register types
- **Destruction candidate listing**: Automatic identification of objects whose retention period has expired, presented as destruction proposals (vernietigingslijsten)
- **Referential integrity checks**: Before destruction, verify that related objects do not have conflicting retention periods or active references
- **Destruction audit trail**: Immutable log of all destruction actions including who proposed, reviewed, approved, and executed destruction, with metadata snapshots of destroyed records
- **Destruction hold/exception**: Ability to place a hold on objects to prevent destruction (e.g., ongoing legal proceedings, WOB/WOO requests)
- **Batch destruction**: Bulk destruction of objects by schema type, retention category, or custom selection
- **NEN 15489 / NEN-ISO 16175-1:2020 compliance fields**: Ensure metadata model supports required archival classification fields

### Out of Scope

- e-Depot transfer (separate change: `edepot-transfer`)
- Retention period configuration (separate change: `retention-management`)
- CSV import/export with ID support (already exists in OpenRegister)
- Physical document destruction tracking

## Acceptance Criteria

1. An authorised user can generate a destruction proposal listing all objects past their retention date
2. Destruction proposals require at least one approval before execution
3. The system checks referential integrity and blocks destruction of objects with active cross-references
4. A destruction hold can be placed on individual objects or entire schemas to prevent destruction
5. All destruction actions produce immutable audit trail entries with metadata snapshots
6. Selectielijsten (VNG format) can be imported and linked to schemas/registers
7. Batch destruction supports filtering by schema, register, retention category, and date range
8. Destruction is irreversible once executed -- data is permanently removed, only the audit trail record remains

## Dependencies

- **retention-management**: Destruction triggers depend on configured retention periods
- **enhanced-audit-trail**: Destruction audit entries should integrate with the general audit trail system
- OpenRegister ObjectService for object lifecycle management
- Nextcloud user/group system for approval roles

## Standards & Regulations

- Archiefwet 1995
- NEN 15489 (NEN-ISO 15489-1:2016)
- NEN-ISO 16175-1:2020
- VNG Selectielijst archiefbescheiden gemeenten
- BIO (Baseline Informatiebeveiliging Overheid)

## Notes

- OpenRegister already has CSV import/export with ID support -- this change focuses solely on the destruction workflow
- The archived change `archivering-vernietiging` covers related ground but this proposal adds selectielijst management and the multi-step approval workflow as distinct capabilities
>>>>>>> origin/development
