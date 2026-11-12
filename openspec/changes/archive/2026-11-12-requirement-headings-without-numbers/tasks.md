# Tasks: requirement-headings-without-numbers

## 1. Headings

- [x] 1.1 Rename the numbered requirement headings in `referential-integrity` and `deletion-audit-trail` to `### Requirement: <text>`. Verify: `openspec validate referential-integrity --strict` and `openspec validate deletion-audit-trail --strict`.
- [x] 1.2 Point the capability rows that cite these requirements at the new headings. Verify: the strict reference check passes for mod-relations, rec-bin, api-expand, hist-deleted-trace and q-dangling.
