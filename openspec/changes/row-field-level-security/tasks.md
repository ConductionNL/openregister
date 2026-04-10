# Tasks: Row and Field Level Security

- [ ] Implement: Schemas MUST support row-level security rules via conditional authorization matching
- [ ] Implement: RLS rules MUST support dynamic variable resolution in match conditions
- [ ] Implement: Schemas MUST support field-level security via property authorization blocks
- [ ] Implement: RLS rules MUST apply consistently to all access methods
- [ ] Implement: FLS MUST apply consistently to GraphQL field resolution
- [ ] Implement: The condition syntax MUST support MongoDB-style operators for match expressions
- [ ] Implement: RLS and FLS MUST be combinable with schema-level RBAC in a layered evaluation chain
- [ ] Implement: RLS condition evaluation MUST happen at the SQL query level for performance
- [ ] Implement: RLS MUST interact correctly with multi-tenancy isolation
- [ ] Implement: FLS MUST strip restricted fields from API responses and export outputs
- [ ] Implement: FLS on create operations MUST skip organisation matching for conditional rules
- [ ] Implement: Security rules MUST be auditable for compliance
- [ ] Implement: Schema property authorization configuration MUST be inspectable via Schema entity methods
- [ ] Implement: CamelCase property names MUST be correctly mapped to snake_case column names in SQL conditions
- [ ] Implement: ConditionMatcher MUST support @self property lookup for system fields
