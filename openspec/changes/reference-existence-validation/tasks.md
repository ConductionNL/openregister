# Tasks: reference-existence-validation Specification

- [ ] Implement: Schema properties MUST support a validateReference configuration
- [ ] Implement: Save MUST reject objects with invalid references when validateReference is enabled
- [ ] Implement: Reference validation MUST resolve target schema via existing $ref resolution
- [ ] Implement: Reference validation MUST work with the object's register context
- [ ] Implement: Reference validation MUST NOT impact update operations for unchanged references
- [ ] Implement: Soft-deleted references MUST be treated as nonexistent
- [ ] Implement: Batch reference validation MUST be optimized for bulk imports
- [ ] Implement: Validation error reporting MUST include structured diagnostic information
- [ ] Implement: Circular reference chains MUST be detected during validation
- [ ] Implement: External URL references MUST support configurable validation
- [ ] Implement: Validation results MUST be cached within a request scope
- [ ] Implement: Admin users MUST be able to bypass reference validation
- [ ] Implement: Reference validation MUST work in GraphQL mutations
- [ ] Implement: Async validation MUST be supported for large batch operations
- [ ] Implement: Validation events MUST be dispatched for notification and extensibility
- [ ] Implement: Schema-configurable validation strictness levels MUST be supported
