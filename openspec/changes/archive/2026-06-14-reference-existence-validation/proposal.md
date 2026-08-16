# reference-existence-validation Specification

## Problem
Add configurable validation that ensures objects referenced via `$ref` properties actually exist before saving. When a schema property has `$ref` pointing to another schema and `validateReference` is enabled, the save pipeline checks that the UUID stored in that property corresponds to an existing object in the target schema.

## Proposed Solution
Add configurable validation that ensures objects referenced via `$ref` properties actually exist before saving. When a schema property has `$ref` pointing to another schema and `validateReference` is enabled, the save pipeline checks that the UUID stored in that property corresponds to an existing object in the target schema. This spec covers the full lifecycle of reference existence checking: single-object saves, bulk imports, GraphQL mutations, soft-deleted reference handling, circular referen
