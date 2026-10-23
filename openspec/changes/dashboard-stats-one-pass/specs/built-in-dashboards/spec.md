## ADDED Requirements

### Requirement: The registers-with-schemas rollup MUST read each table and the audit trail at most once

Within one `getRegistersWithSchemas()` call, `DashboardService` MUST count each register-schema table at most once. It MUST aggregate the audit trail at most once, grouped by register and schema. It MUST read the webhook log statistics at most once. The system totals, each register, each schema and the orphaned row MUST be computed as sums over those aggregates. The number of queries MUST therefore not grow with the number of registers times schemas, and the orphaned statistics MUST NOT be computed with one exclusion condition per valid register-schema pair.

#### Scenario: One pass over the tables and the audit trail
- **GIVEN** a register with two schemas and one table whose register-schema combination is no longer valid
- **WHEN** `getRegistersWithSchemas()` runs
- **THEN** each table MUST be counted exactly once
- **AND** the audit trail MUST be aggregated by register and schema exactly once
- **AND** the totals MUST equal the sum over all tables and audit groups
- **AND** the register and schema rows MUST equal the sums over their own tables and audit groups
- **AND** the orphaned row MUST equal the sum over the tables and audit groups whose combination is not valid, counting audit rows without a register or schema as orphaned

#### Scenario: The response does not change
- **WHEN** `getRegistersWithSchemas()` runs with or without `registerId` and `schemaId`
- **THEN** the response MUST keep the same envelope and the same statistic values as before this requirement
