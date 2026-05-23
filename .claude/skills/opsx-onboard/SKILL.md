---
name: opsx-onboard
description: Guided onboarding - walk through a complete OpenSpec workflow cycle with narration
metadata:
  category: Workflow
  tags: [workflow, onboarding, tutorial, learning]
---

Guide the user through their first complete OpenSpec workflow cycle. This is a teaching experience—you'll do real work in their codebase while explaining each step.

---

## Preflight

Before starting, check if OpenSpec is initialized:

```bash
openspec status --json 2>&1 || echo "NOT_INITIALIZED"
```

**If not initialized:**
> OpenSpec isn't set up in this project yet. Run `openspec init` first, then come back to `/opsx-onboard`.

Stop here if not initialized.

**If initialized**, also validate the configuration:

```bash
openspec validate --all 2>&1
```

If validation fails, display the errors and ask whether the user wants to fix them before continuing or proceed anyway.

---

## Phase 1: Welcome

Deliver the Phase 1 message from [references/phase-messages.md](references/phase-messages.md).

---

## Phase 2: Task Selection

### Codebase Analysis

Scan the codebase for small improvement opportunities. Look for:

1. **TODO/FIXME comments** - Search for `TODO`, `FIXME`, `HACK`, `XXX` in code files
2. **Missing error handling** - `catch` blocks that swallow errors, risky operations without try-catch
3. **Functions without tests** - Cross-reference `src/` with test directories
4. **Type issues** - `any` types in TypeScript files (`: any`, `as any`)
5. **Debug artifacts** - `console.log`, `console.debug`, `debugger` statements in non-debug code
6. **Missing validation** - User input handlers without validation

Also check recent git activity:
```bash
git log --oneline -10 2>/dev/null || echo "No git history"
```

### Present Suggestions

From your analysis, deliver the Phase 2 Task Suggestions message from [references/phase-messages.md](references/phase-messages.md), filling in the real findings.

**If nothing found:** Fall back to asking what the user wants to build:
> I didn't find obvious quick wins in your codebase. What's something small you've been meaning to add or fix?

### Scope Guardrail

If the user picks or describes something too large (major feature, multi-day work), deliver the Phase 2b Scope Guardrail message from [references/phase-messages.md](references/phase-messages.md).

Let the user override if they insist—this is a soft guardrail.

---

## Phase 3: Explore Demo

Once a task is selected, briefly introduce explore mode:

> Before we create a change, let me quickly show you **explore mode**—it's how you think through problems before committing to a direction.

Spend 1-2 minutes investigating the relevant code:
- Read the file(s) involved
- Draw a quick ASCII diagram if it helps
- Note any considerations

Deliver the Phase 3 Quick Exploration message from [references/phase-messages.md](references/phase-messages.md), filling in your actual analysis.

**PAUSE** - Wait for user acknowledgment before proceeding.

---

## Phase 4: Create the Change

Deliver the Phase 4 Creating a Change message from [references/phase-messages.md](references/phase-messages.md).

**DO:** Create the change with a derived kebab-case name:
```bash
openspec new change "<derived-name>"
```

Then deliver the Phase 4 post-creation message from [references/phase-messages.md](references/phase-messages.md), filling in the real change name.

---

## Phase 5: Proposal

Deliver the Phase 5 Proposal message from [references/phase-messages.md](references/phase-messages.md).

**DO:** Draft the proposal content (don't save yet) using [examples/proposal-template.md](examples/proposal-template.md) as the template. Show the draft to the user.

**PAUSE** - Wait for user approval/feedback.

After approval, save the proposal:
```bash
openspec instructions proposal --change "<name>" --json
```
Then write the content to `openspec/changes/<name>/proposal.md`.

Deliver the Phase 5 post-save message from [references/phase-messages.md](references/phase-messages.md).

---

## Phase 6: Specs

Deliver the Phase 6 Specs message from [references/phase-messages.md](references/phase-messages.md).

**DO:** Create the spec file:
```bash
mkdir -p openspec/changes/<name>/specs/<capability-name>
```

Draft the spec content using [examples/specs-template.md](examples/specs-template.md) as the template. Save to `openspec/changes/<name>/specs/<capability>/spec.md`.

---

## Phase 7: Design

Deliver the Phase 7 Design message from [references/phase-messages.md](references/phase-messages.md).

**DO:** Draft design.md using [examples/design-template.md](examples/design-template.md) as the template. Save to `openspec/changes/<name>/design.md`.

---

## Phase 8: Tasks

Deliver the Phase 8 Tasks message from [references/phase-messages.md](references/phase-messages.md).

**DO:** Generate tasks based on specs and design using [examples/tasks-template.md](examples/tasks-template.md) as the template.

**PAUSE** - Wait for user to confirm they're ready to implement.

Save to `openspec/changes/<name>/tasks.md`.

---

## Phase 9: Apply (Implementation)

Deliver the Phase 9 Implementation message from [references/phase-messages.md](references/phase-messages.md).

**DO:** For each task:

1. Announce: "Working on task N: [description]"
2. Implement the change in the codebase
3. Reference specs/design naturally: "The spec says X, so I'm doing Y"
4. Mark complete in tasks.md: `- [ ]` → `- [x]`
5. Brief status: "✓ Task N complete"

Keep narration light—don't over-explain every line of code.

After all tasks, deliver the Phase 9 Implementation Complete message from [references/phase-messages.md](references/phase-messages.md), filling in the real task list.

---

## Phase 10: Archive

Deliver the Phase 10 Archiving message from [references/phase-messages.md](references/phase-messages.md).

**DO:**
```bash
openspec archive "<name>"
```

Deliver the Phase 10 post-archive message from [references/phase-messages.md](references/phase-messages.md), filling in the real archive path.

---

## Phase 11: Recap & Next Steps

Deliver the Phase 11 Congratulations message from [references/phase-messages.md](references/phase-messages.md).

---

## Graceful Exit Handling

### User wants to stop mid-way

If the user says they need to stop, want to pause, or seem disengaged, deliver the Graceful Exit: Mid-Workflow Stop message from [references/phase-messages.md](references/phase-messages.md), filling in the real change name.

Exit gracefully without pressure.

### User just wants command reference

If the user says they just want to see the commands or skip the tutorial, deliver the Graceful Exit: Quick Reference Request message from [references/phase-messages.md](references/phase-messages.md).

Exit gracefully.

---

## OpenSpec Philosophy (state these explicitly during the Welcome message)

The onboarding should teach the *why* alongside the *what*. State these named principles up front so the user understands the framework before walking through it:

- **Spec-first development** — write what the change does (proposal), what the system should be (specs), how you'll know it works (test-plan), and only then how you'll build it (tasks). Implementation lands last, against a contract you've already agreed to.
- **Delta specs as reviewable diffs** — changes never edit main `openspec/specs/` directly. They live as deltas in `openspec/changes/<name>/specs/` and merge in via `/opsx-archive` only when verified. This makes change scope explicit and reviewable.
- **Artifacts as a contract chain** — proposal → specs → design → tasks → implementation → verification → archive. Each artifact has a single owner-skill (`/opsx-explore`, `/opsx-ff`, `/opsx-apply`, `/opsx-verify`, `/opsx-archive`). Skipping a link risks unverified work.
- **One change at a time per branch** — the branching strategy (`feature/<name>`) and one-issue-per-change pairing (`/opsx-plan-to-issues`) keep work atomic.

## Guardrails (each with rationale)

- **Follow the EXPLAIN → DO → SHOW → PAUSE pattern** at key transitions (after explore, after proposal draft, after tasks, after archive). *Why:* learning sticks when each step is named and surfaced before it happens.
- **Keep narration light** during implementation—teach without lecturing. *Why:* an over-narrated walkthrough drowns the actual workflow signal.
- **Don't skip phases** even if the change is small—the goal is teaching the workflow. *Why:* skipping phases trains the wrong muscle memory; once the workflow is internalized, the user can shortcut on their own.
- **Pause for acknowledgment** at marked points, but don't over-pause. *Why:* checkpoints give the user a chance to ask before they're lost; over-pausing turns onboarding into a quiz.
- **Handle exits gracefully**—never pressure the user to continue. *Why:* onboarding is opt-in; pressure poisons the experience.
- **Use real codebase tasks**—don't simulate or use fake examples. *Why:* fake examples don't survive the user's first real change; real tasks build immediate competence.
- **Adjust scope gently**—guide toward smaller tasks but respect user choice. *Why:* the user's instinct about scope is usually right; the skill's job is to surface trade-offs, not override them.

## Learn more (point users at these on completion)

After the final phase, list these resources in the chat summary:

- `openspec/architecture/RULES.md` — compact ruleset for this project
- `openspec/architecture/adr-*.md` — Architectural Decision Records explaining *why* the project is shaped the way it is
- `CLAUDE.md` — project instructions (Quick Reference, dev commands, model selection guidance)
- `.claude/docs/writing-skills.md`, `writing-specs.md`, `writing-docs.md` — authoring conventions for skills, specs, and docs
- `openspec --help` — CLI reference for the openspec tool itself

Frame these as "next steps for going deeper" rather than required reading.
