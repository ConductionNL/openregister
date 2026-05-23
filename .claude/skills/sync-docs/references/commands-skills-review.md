# Commands and Skills Review Protocol

After applying documentation changes, audit commands and skills in two parts.

---

## Part A — Change-impact check

Run this unconditionally. Read all `SKILL.md` files in `.claude/skills/` and compare them against the documentation changes just made in this sync. Look for:

- **Stale references** — a command or skill references a file path, section heading, or doc name that was renamed or moved during this sync
- **Outdated instructions** — a command or skill instructs Claude to follow a workflow, use a tool, or apply a principle that was updated in the docs just synced
- **Missing guidance** — the updated doc introduced a new rule or convention (e.g. a new writing anti-pattern, a new source of truth entry) that a command or skill should now follow but doesn't mention
- **Redundant inline content** — a command or skill restates something now clearly documented in a doc file; replace with a link

After completing Part A, present its findings and ask using AskUserQuestion:

**"Part A (change-impact check) complete. Would you also like to run the standalone health check on all skill files (`.claude/skills/`)?"**

The standalone health check reads every command and skill file independently and audits the full content — broken links, stale references, anti-patterns, scope creep, and more. It is thorough but takes significantly longer.

- **Yes, run the health check** — continue to Part B
- **No, skip** — go straight to the combined summary with Part A findings only

---

## Part B — Standalone health check *(optional)*

Only run if the user selected "Yes" above.

Run the full 8-point standalone audit on every command and skill file in `.claude/skills/`. The protocol — what to load as reference, the 8 checks (broken links, stale references, duplicated content, missing cross-references, writing anti-patterns, outdated workflow steps, removed content, scope creep), and depth guidance — lives in [skill-health-check.md](skill-health-check.md). Read it before starting Part B.

---

After presenting Part B findings (or if B was skipped), ask using AskUserQuestion:

**"Would you also like to run a doc structure review of `{GITHUB_REPO}/docs/claude/`?"**

- **Yes, run doc structure review** — continue to Part C
- **No, skip** — go straight to the combined summary

---

## Part C — Doc structure review *(optional)*

Only run if the user selected "Yes" above.

Run the holistic structure review across all files in `{GITHUB_REPO}/docs/claude/` — looking at how docs relate to each other (overlap, differentiation, cross-references, proliferation). The 4 checks and depth guidance live in [doc-structure-review.md](doc-structure-review.md). Read it before starting Part C.

---

## Combined summary and confirmation

Present a single consolidated summary covering all parts that were run.

```
Dev Docs Review
────────────────────────────────────────
Skills & Commands  (Part A + Part B — or "Part A only" if B was skipped)

  .claude/skills/sync-docs/SKILL.md               — N items  [A: 1, B: 2]
  .claude/skills/opsx-ff/SKILL.md                  — up to date
  .claude/skills/test-counsel/SKILL.md             — N items  [B: 1 stale link, 1 anti-pattern]
  .claude/skills/test-scenario-create/SKILL.md     — up to date
  ...
  Subtotal: N items across M skills

Doc Structure  (Part C — omit section if C was skipped)

  docs/claude/workflow.md + getting-started.md    — N items  [C: overlap, no cross-ref]
  docs/claude/docker.md                           — up to date
  ...
  Subtotal: N items across M doc pairs

Total: N items across M files
```

For each flagged item, include a one-line description of the issue and which check (1–8, Part A, or C) identified it.

Then ask using AskUserQuestion:

**"Documentation sync is complete. I found N items across M files. What would you like to do?"**
- **Update all** — apply all identified updates
- **Review each** — go through each file one at a time, confirm before each update
- **Show proposed changes first** — show all diffs, then confirm once
- **Skip** — leave commands and skills as-is for now

Apply updates following the same guardrails as Phase 4 — never change the intent of a command or skill without user confirmation. Flag anything ambiguous as `[Verify]` rather than making assumptions.

```
All dev docs are now current.
```
