# Content Versioning

## Problem
Content versioning provides a complete lifecycle for register objects, enabling users to track every change as a numbered version, create named draft versions for work-in-progress edits, compare any two versions with field-level diffs, and roll back to any previous state. This capability is essential for government compliance (WOO, Archiefwet), editorial workflows where changes require review before publication, and multi-user collaboration where concurrent edits must be managed safely.

## Proposed Solution
Implement Content Versioning following the detailed specification. Key requirements include:
- Requirement: Every save operation MUST produce a new version
- Requirement: Objects MUST support a draft/published lifecycle
- Requirement: Drafts MUST be promotable to published version
- Requirement: The system MUST support version comparison with visual diffs
- Requirement: The system MUST support version rollback

## Scope
This change covers all requirements defined in the content-versioning specification.

## Success Criteria
- Version increment on first creation
- Version increment on update
- Version increment on bulk update
- Version number persists across API responses
- Create a draft version
