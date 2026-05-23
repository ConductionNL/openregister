# Loop Report Template

Use this template in **Step 15** of `opsx-apply-loop` to deliver the final report.

Fill in all placeholders: loop log entries, summary table, pass/fail counts, remaining issues list.

---

## Success report

Write the report to a file **first, before any cleanup**:

```bash
cat > "${LOG_DIR}/final-result.md" << 'EOF'
<the full report text below>
EOF
```

Then display it:

```
## opsx-apply-loop — {APP}/{CHANGE_NAME}

### Loop Log
| Iter | Phase         | Result                    | Notes                       |
|------|---------------|---------------------------|-----------------------------|
| 1    | apply         | 4 tasks implemented       | Quality: ✓ pass             |
| 1    | verify        | 1 CRITICAL                | Missing unit test           |
| 2    | apply         | Fixed: added test         | Quality: ✓ pass             |
| 2    | verify        | ✓ Clean                   | —                           |
| T1   | test-functional | ✓ Pass                  | —                           |
| —    | archive       | ✓ Archived               | {APP}/openspec/changes/archive/ |

### Summary
- App: {APP}
- Branch: feature/{ISSUE_NUMBER}/{CHANGE_NAME} (committed)
- Apply→verify iterations used: 2 / 5
- Test iterations used: 1 / 3 (or: tests skipped)
- Final verify status: ✓ Clean
- Warnings noted: 0
- Archive: ✓ {APP}/openspec/changes/archive/YYYY-MM-DD-{CHANGE_NAME}/
- GitHub issue: #{ISSUE_NUMBER} synced + closed / left open
```

---

## Log cleanup rules

Evaluate each log file after writing `final-result.md`. The goal is to delete files whose content is fully superseded (all issues they mention were resolved), while keeping any file that references an issue still open in the final state.

**What counts as unresolved**: any CRITICAL or WARNING that appears in the final `result` or final `container.log` and was NOT fixed in a subsequent iteration. Specifically:
- `WARNINGS_ONLY=true` in the final result → there are still warnings; the final `container.log` is needed as evidence
- A test-failures file → its failures are unresolved UNLESS a subsequent `container.log` or `result` shows those areas were fixed and verify passed clean after

| File type | Delete if… | Keep if… |
|---|---|---|
| `apply-loop-*-result.log` (non-final) | All issues it reported were fixed in a later iteration | It's the most recent, or its issues are still open |
| `apply-loop-*-container.log` (non-final) | All CRITICALs/WARNINGs it reported were fixed in a later run | It references issues still unresolved in the final state |
| `apply-loop-*-container.log` (final/most recent) | — | Always keep |
| `apply-loop-*-test-failures-*.log` | The specific failures it lists were fixed (a later container run shows verify-clean covering those areas) | The failures were never resolved |

Always keep: `final-result.md`, the most recent `container.log`, the most recent `result`.

Log which files were deleted and which were kept, with the reason for each kept file.

---

## Exhaustion report (loop exhausted — CRITICAL issues remain)

```
⛔ Loop stopped after 5 iterations — CRITICAL issues remain.

### Remaining CRITICAL issues:
- <issue 1 with file:line reference>
- <issue 2 with file:line reference>
```

The container has exited. The feature branch in `{APP}/` has partial changes. Use **AskUserQuestion** to ask: "How would you like to proceed?"

- **Fix manually, then re-run** — edit files in `{APP}/` on the feature branch and run `/opsx-apply-loop {APP} {CHANGE_NAME}` again
- **Open verify interactively** — run `/opsx-verify {CHANGE_NAME}` from within `{APP}/` to inspect in detail
- **Commit partial work and open a draft PR** — commit what's there, open a draft PR for review
- **Abandon branch** — `cd {APP} && git checkout -` and `git branch -D feature/{ISSUE_NUMBER}/{CHANGE_NAME}`
