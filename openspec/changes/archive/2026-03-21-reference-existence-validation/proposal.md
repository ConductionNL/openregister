# reference-existence-validation Specification

## Problem
Add configurable validation that ensures objects referenced via `$ref` properties actually exist before saving. When a schema property has `$ref` pointing to another schema and `validateReference` is enabled, the save pipeline checks that the UUID stored in that property corresponds to an existing object in the target schema. This spec covers the full lifecycle of reference existence checking: single-object saves, bulk imports, GraphQL mutations, soft-deleted reference handling, circular reference detection, external URL references, validation caching, configurable strictness, admin bypass, async batch validation, and event-driven notification of validation failures.
**Source**: Core OpenRegister data integrity capability. Ensures that `$ref` pointers between objects are valid at write time, complementing the referential-integrity spec which handles cascading behavior at delete time.
**Cross-references**: referential-integrity (delete-time enforcement), deletion-audit-trail (audit logging), content-versioning (version impact), bulk-object-operations (import pipeline), graphql-api (mutation validation).

## Proposed Solution
Implement reference-existence-validation Specification following the detailed specification. Key requirements include:
- Requirement: Schema properties MUST support a validateReference configuration
- Requirement: Save MUST reject objects with invalid references when validateReference is enabled
- Requirement: Reference validation MUST resolve target schema via existing $ref resolution
- Requirement: Reference validation MUST work with the object's register context
- Requirement: Reference validation MUST NOT impact update operations for unchanged references

## Scope
This change covers all requirements defined in the reference-existence-validation specification.

## Success Criteria
- Property with validateReference enabled
- Property with validateReference disabled (default)
- Single-value reference to nonexistent object
- Array reference with one invalid UUID
- Array reference with all valid UUIDs
