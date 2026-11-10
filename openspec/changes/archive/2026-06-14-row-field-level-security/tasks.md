# Tasks: Row and Field Level Security

- [x] Implement: Schemas MUST support row-level security rules via conditional authorization matching
- [x] Implement: RLS rules MUST support dynamic variable resolution in match conditions
- [x] Implement: Schemas MUST support field-level security via property authorization blocks
- [x] Implement: RLS rules MUST apply consistently to all access methods
- [x] Implement: FLS MUST apply consistently to GraphQL field resolution
- [x] Implement: The condition syntax MUST support MongoDB-style operators for match expressions
- [x] Implement: RLS and FLS MUST be combinable with schema-level RBAC in a layered evaluation chain
- [x] Implement: RLS condition evaluation MUST happen at the SQL query level for performance
- [x] Implement: RLS MUST interact correctly with multi-tenancy isolation
- [x] Implement: FLS MUST strip restricted fields from API responses and export outputs
- [x] Implement: FLS on create operations MUST skip organisation matching for conditional rules
- [x] Implement: Security rules MUST be auditable for compliance
- [x] Implement: Schema property authorization configuration MUST be inspectable via Schema entity methods
- [x] Implement: CamelCase property names MUST be correctly mapped to snake_case column names in SQL conditions
- [x] Implement: ConditionMatcher MUST support @self property lookup for system fields
