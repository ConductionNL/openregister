# Tasks

This is a documentation-only retrofit. All 223 methods in batch `fe-views-3` are
tagged `@spec exclude <reason>` as UI plumbing. No new REQs are minted, so there are
no implementation tasks — only the annotation pass.

- [x] task-1: Annotate all 223 uncovered `src/views/**/*.vue` methods with
  `@spec exclude <reason>` (UI plumbing: list/detail rendering, data fetching for
  display, formatting helpers, dialog/sidebar toggles, pagination/sort/selection
  handlers, and lifecycle hooks). No new REQs drafted — behaviors are fully covered
  at the contract level by the owning backend capabilities or are pure presentation.
