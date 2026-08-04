# Tasks

All tasks are marked `[x]` because the code already exists. This is a retrofit — tasks describe retroactive annotation, not new implementation work.

All-exclude annotation change. No REQs minted, so there is no spec delta and no `--strict` validation step.

## Annotation tasks

- [x] task-1: Tag all 207 batch methods under `src/components/` with JSDoc `@spec exclude <reason>` (ADR-003).
- [x] task-2: Verify 0 untagged methods remain in the batch.
- [x] task-3: Confirm every added line is comment-only (no functional code change).
