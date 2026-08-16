# Deletion Audit Trail

## Problem
Provide a comprehensive audit and lifecycle management system for all deletion operations in OpenRegister, encompassing soft delete (marking objects as deleted without physical removal), configurable retention before permanent purge, restore from soft delete, cascade delete tracking, and full GDPR-compliant audit trail entries. The spec ensures that every deletion -- whether user-initiated, cascade-triggered, or system-scheduled -- is recorded with sufficient context to reconstruct what happened, why, and by whom, satisfying Dutch government compliance requirements (BIO, AVG/GDPR Article 30, Archiefwet 1995, NEN-ISO 16175-1:2020).
This spec builds on the existing soft-delete infrastructure (`ObjectEntity.deleted`, `DeleteObject`, `DeletedController`) and integrates tightly with the immutable audit trail (`audit-trail-immutable` spec), archiving/destruction lifecycle (`archivering-vernietiging` spec), and referential integrity enforcement (`referential-integrity` spec).

## Proposed Solution
Implement Deletion Audit Trail following the detailed specification. Key requirements include:
- Requirement 1: Deletions MUST use soft delete by default, marking objects as deleted without physical removal
- Requirement 2: The system MUST support configurable retention periods before purge
- Requirement 3: Soft-deleted objects MUST be restorable through the trash API
- Requirement 4: Permanent deletion (purge) MUST require prior soft delete and authorization
- Requirement 5: Full object snapshot MUST be preserved in the audit trail before deletion

## Scope
This change covers all requirements defined in the deletion-audit-trail specification.

## Success Criteria
- User-initiated soft delete via API
- Soft-deleted object excluded from normal queries
- Soft-deleted object still accessible with includeDeleted flag
- System user deletion when no user session exists
- Cache invalidation after soft delete
