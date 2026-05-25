# Retrofit: Filter Sidebar Tabs (reverse-spec)

## Why

The OpenRegister frontend ships four filter-sidebar components (`EntitiesSidebar`, `WebhooksSidebar`, `DashboardSideBar`, `DeletedSideBar`) that share a common UX vocabulary (debounced search, register/schema cascade, URL-as-state) but were never documented in `openspec/specs/`. The Bucket 2a coverage scan (2026-05-24) surfaced four public methods (`handleSearchInput` x2, `handleRegisterChange`, `applyFilters`) with no docblocks and no `@spec` annotations.

This ghost change reverse-engineers a new `files-sidebar-tabs` capability spec from the observed code, captures three REQs describing the shared behaviour, and annotates the four methods with `@spec` pointers back to this change's tasks. No production code changes; this is an annotation-only retrofit so future edits to these methods can be reviewed against an explicit contract.

## What changes

- Add new capability spec `openspec/specs/files-sidebar-tabs/spec.md` (3 REQs, all extracted from observed behaviour).
- Add ghost change `retrofit-2026-05-24-files-sidebar-tabs/` with delta + tasks.
- Annotate 4 methods with `@spec` pointers to this change's tasks file.

## Impact

- **Specs**: new capability `files-sidebar-tabs`.
- **Code**: docblock-only edits in 4 Vue SFCs; no runtime behaviour changes.
- **Risk**: none — annotation retrofit.
