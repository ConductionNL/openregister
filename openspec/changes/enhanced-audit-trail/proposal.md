# Proposal: enhanced-audit-trail

## Summary

Implement a complete, immutable audit trail on all object mutations in OpenRegister, recording who changed what, when, with old/new values. Includes verwerkingenlogging for AVG/GDPR compliance and integration with BIO logging requirements. This is distinct from the archived `audit-trail-immutable` change by focusing on the practical audit UX, verwerkingenlogging API, and zaak-history integration rather than the cryptographic storage layer.

## Demand Evidence

**Cluster: Logging/audit** -- 157 tenders, 463 requirements
**Cluster: Zaak history / audit trail** -- 86 tenders, 179 requirements
**Cluster: Audit trail** -- 19 tenders, 29 requirements
**Combined**: 262 tenders, 671 requirements

### Sample Requirements from Tenders

1. **Gemeente Hilversum**: "De Oplossing beschikt over een niet-muteerbare audit-trail met daarin minimaal de gebeurtenis; de benodigde informatie die nodig is om het incident met hoge mate van zekerheid te herleiden tot een natuurlijk persoon."
2. **Gemeente Hilversum**: "Met de Oplossing wordt alle gestructureerde informatie en alle ongestructureerde informatie gearchiveerd bij de afgesloten zaak, inclusief de audittrail van de zaak."
3. **Gemeente Winterswijk**: "De Oplossing beschikt over een niet-muteerbare audit-trail met daarin minimaal de gebeurtenis; de benodigde informatie die nodig is om het incident met hoge mate van zekerheid te herleiden tot een natuurlijk persoon."
4. **Gemeente Lochem**: "Verwerkingen van gebruikers worden gelogd. Deze verwerkingen worden gelogd volgens de BIO (12.4.1.1. en 2)."
5. **Rijkswaterstaat**: "Verwerkingsregister vereist conform AVG."
6. **Gemeente Deventer**: "Logging, audittrail, berichtherstel."

## Scope

### In Scope

- **Field-level change tracking**: Record old and new values for every field modified in an object mutation
- **Audit trail viewer**: UI component showing chronological history of all changes to an object, with diff view
- **Verwerkingenlogging API**: REST API endpoint conforming to the VNG Verwerkingenlogging standard for registering and querying data processing activities (verwerkingsactiviteiten)
- **BIO logging compliance**: Log entries include all fields required by BIO 12.4.1 (event type, timestamp, user identity, source IP, affected resource, outcome)
- **Bulk operation logging**: Audit trail entries for bulk imports, bulk updates, and bulk deletions with summary records
- **Audit trail export**: Export audit trail data in structured formats (JSON, CSV) for compliance reporting
- **Retention of audit data**: Configurable retention periods for audit trail data (minimum 10 years for government records)
- **Read access logging**: Optional logging of read/view operations on objects containing personal data (AVG Article 30)
- **API mutation logging**: All API-driven changes are logged with the calling application/token identity

### Out of Scope

- Cryptographic hash chaining (covered by archived `audit-trail-immutable` spec)
- Object destruction workflow (separate change: `archival-destruction-workflow`)
- CSV import/export (already exists)
- Application-level error logging (Nextcloud's own logging handles this)

## Acceptance Criteria

1. Every create, update, and delete operation on an object produces an audit trail entry
2. Audit trail entries include: timestamp, user ID, user display name, action type, affected object ID, field-level changes (old value, new value)
3. Audit trail entries are immutable -- they cannot be modified or deleted through the application
4. An audit trail viewer in the object detail view shows all changes chronologically with expandable diffs
5. A verwerkingenlogging API endpoint allows external systems to query processing activities by person (BSN), time range, or processing purpose
6. Bulk operations produce summary audit entries linking to individual change records
7. Audit trail data can be exported as JSON or CSV for compliance reporting
8. Read access logging can be enabled per schema for objects containing personal data
9. Audit trail retention is configurable and defaults to 10 years

## Dependencies

- OpenRegister ObjectService (hooks into save/update/delete lifecycle)
- OpenRegister Entity framework for change detection
- Nextcloud user session for identity tracking
- No external service dependencies (self-contained within OpenRegister)

## Standards & Regulations

- AVG/GDPR Article 30 (record of processing activities)
- BIO (Baseline Informatiebeveiliging Overheid) -- section 12.4.1 (event logging)
- VNG Verwerkingenlogging API standard
- Archiefwet 1995 (audit trail as part of archival record)
- NEN-ISO 15489-1:2016 (records management -- metadata requirements)

## Notes

- OpenRegister already has CSV import/export with ID support
- The archived `audit-trail-immutable` change covers the storage-layer foundation; this change focuses on the practical audit UX, verwerkingenlogging compliance, and the field-level diff capability
- Verwerkingenlogging is a VNG standard that is increasingly required in government tenders -- it registers which personal data was accessed/modified and for what purpose
