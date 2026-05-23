# Doc Structure Review — Holistic Audit (Phase 6 Part C)

Read all files in `{GITHUB_REPO}/docs/claude/` and audit the structure **holistically** — not individual file content, but how the files relate to each other.

## What to check

1. **Overlap in purpose** — do any two docs serve the same audience for the same concern? A doc that overlaps heavily in topic and audience with another is a maintenance risk: they will diverge over time. Flag pairs that cover more than ~50% of the same ground.

2. **Differentiation** — for docs that overlap in subject or audience, is each doc's distinct role clear from the first paragraph? A reader picking up either file should immediately understand why this one exists and how it differs from the other. If the distinction is not visible from the intro, flag it.

3. **Missing cross-references between overlapping docs** — when two docs share topic or audience, do they reference each other at the relevant point? Could the overlap in one file be reduced by replacing duplicated content with a link to the other? Flag cases where a single well-placed cross-reference would eliminate meaningful duplication.

4. **Doc proliferation** — docs that cover a concern narrow enough to warrant only a section in an existing file. A standalone doc is justified when it has internal navigation needs, targets a distinct audience, or is frequently referenced from multiple places. A short, narrowly-scoped doc that is always read alongside one other doc is a candidate for merger.

## Depth guidance

- Read every file in `{GITHUB_REPO}/docs/claude/`
- For each pair of docs that share subject matter, run checks 1–4
- Flag only real structural issues — a different angle on the same topic is not overlap; repetition of the same content for the same audience is
