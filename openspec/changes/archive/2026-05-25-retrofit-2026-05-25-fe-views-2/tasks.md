# Tasks

All tasks are marked `[x]` because the code already exists. This is a documentation-only retrofit — tasks describe retroactive `@spec exclude` annotation, not new implementation work. No new REQs are minted, so there is no spec delta.

## Exclusions (UI plumbing)

- [x] task-1: Annotate the 223 chunk methods across 18 `src/views/**/*.vue` files with `@spec exclude <reason>`. Each method is list/detail/settings UI plumbing — pagination, selection, formatting, lifecycle fetch, store wiring, navigation, or clipboard/download glue — whose user-facing behavior is already owned by an existing capability. Reasons are method-specific per ADR-003 (bare `exclude` is invalid).
