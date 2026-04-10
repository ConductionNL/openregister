# Immutable Audit Trail

## Problem
Implement an immutable audit trail with cryptographic hash chaining for all register operations, ensuring every create, read (of sensitive data), update, and delete is recorded in a tamper-evident log that satisfies Dutch government compliance requirements (BIO2, AVG/GDPR Article 30, Archiefwet 1995, NEN-ISO 16175-1:2020). The audit trail MUST be independently verifiable, exportable for compliance auditing, and retained for configurable periods (minimum 10 years for government records). It serves as the foundational evidence layer for content versioning, object reversion, archiving/destruction workflows, and referential integrity tracking.
**Tender demand**: 56% of analyzed government tenders require immutable audit trail capabilities. An additional 77% reference archiving requirements that depend on audit trail integrity.

## Proposed Solution
Implement Immutable Audit Trail following the detailed specification. Key requirements include:
- Requirement 1: Every mutation MUST produce an immutable audit trail entry
- Requirement 2: The audit trail MUST use cryptographic hash chaining for tamper detection
- Requirement 3: Audit trail entries MUST NOT be deletable or modifiable through the application
- Requirement 4: The audit trail MUST record comprehensive BIO2 and GDPR compliance fields
- Requirement 5: Sensitive data read operations MUST be audited

## Scope
This change covers all requirements defined in the audit-trail-immutable specification.

## Success Criteria
- Audit entry for object creation
- Audit entry for object update with field-level diff
- Audit entry for object deletion
- Audit entry for cascade deletion
- Silent mode suppresses audit trail for bulk imports
