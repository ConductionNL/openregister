# Tasks

All 224 batch methods are frontend modal UI plumbing and are tagged
`@spec exclude <reason>` (ADR-003 exclude convention). No new REQs are minted, so
there are no spec-linked tasks. This is a delta-less, all-exclude annotation pass.

- [x] task-1: Tag all 224 uncovered `src/modals/` methods `@spec exclude <reason>` — dialog lifecycle, reactive form setters, computed/display helpers, formatters, wizard navigation, multi-select toggles, file-picker handlers, search passthroughs, and operational-job progress readouts (no novel domain contract beyond entity-management-modals / platform-administration-modals).
