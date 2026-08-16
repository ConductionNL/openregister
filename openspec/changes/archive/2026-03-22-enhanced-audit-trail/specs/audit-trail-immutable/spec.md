## ADDED Requirements

### Requirement: The AuditTrail entity MUST include hash and previousHash fields
The `AuditTrail` entity MUST be extended with `hash` and `previousHash` string fields for cryptographic chain integrity.

#### Scenario: New audit trail entry includes hash fields in JSON
- **WHEN** an audit trail entry with hash chaining is serialized to JSON
- **THEN** the JSON output MUST include `hash` and `previousHash` string fields
- **AND** both fields MUST be 64-character hexadecimal strings (SHA-256 output)

#### Scenario: Legacy entry without hash fields
- **WHEN** an audit trail entry created before hash chaining is serialized to JSON
- **THEN** the JSON output MUST include `hash` and `previousHash` as null values

## MODIFIED Requirements

### Requirement: Audit trail entries MUST NOT be deletable or modifiable
No user, including administrators, MUST be able to modify or delete audit trail entries through the application.

#### Scenario: Reject audit trail deletion via API
- GIVEN an admin user attempts to DELETE `/api/audit-trails/{id}`
- THEN the system MUST return HTTP 405 Method Not Allowed
- AND the response MUST include `{"error": "Audit trail entries cannot be deleted"}`
- AND the audit entry MUST remain unchanged

#### Scenario: Reject audit trail modification via PUT
- GIVEN an admin attempts to PUT `/api/audit-trails/{id}` with modified data
- THEN the system MUST return HTTP 405 Method Not Allowed
- AND the response MUST include `{"error": "Audit trail entries cannot be modified"}`

#### Scenario: Reject audit trail modification via PATCH
- GIVEN an admin attempts to PATCH `/api/audit-trails/{id}` with modified data
- THEN the system MUST return HTTP 405 Method Not Allowed
- AND the response MUST include `{"error": "Audit trail entries cannot be modified"}`
