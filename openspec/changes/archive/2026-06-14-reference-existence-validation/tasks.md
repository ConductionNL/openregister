# Tasks: reference-existence-validation Specification

- [x] Implement: Schema properties MUST support a validateReference configuration
- [x] Implement: Save MUST reject objects with invalid references when validateReference is enabled
- [x] Implement: Reference validation MUST resolve target schema via existing $ref resolution
- [x] Implement: Reference validation MUST work with the object's register context
- [x] Implement: Reference validation MUST NOT impact update operations for unchanged references
- [x] Implement: Soft-deleted references MUST be treated as nonexistent
- [x] Implement: Batch reference validation MUST be optimized for bulk imports
- [x] Implement: Validation error reporting MUST include structured diagnostic information
- [x] Implement: Circular reference chains MUST be detected during validation
- [x] Implement: External URL references MUST support configurable validation
- [x] Implement: Validation results MUST be cached within a request scope
- [x] Implement: Admin users MUST be able to bypass reference validation
- [x] Implement: Reference validation MUST work in GraphQL mutations
- [x] Implement: Async validation MUST be supported for large batch operations
- [x] Implement: Validation events MUST be dispatched for notification and extensibility
- [x] Implement: Schema-configurable validation strictness levels MUST be supported
