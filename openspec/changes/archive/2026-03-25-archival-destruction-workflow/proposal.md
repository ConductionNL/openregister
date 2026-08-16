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
