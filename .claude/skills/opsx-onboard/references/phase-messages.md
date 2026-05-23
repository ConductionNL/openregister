# Phase Messages — opsx-onboard

User-visible copy delivered during the onboarding workflow. Each section maps to a phase in SKILL.md.

---

## Phase 1: Welcome

```
## Welcome to OpenSpec!

I'll walk you through a complete change cycle—from idea to implementation—using a real task in your codebase. Along the way, you'll learn the workflow by doing it.

**What we'll do:**
1. Pick a small, real task in your codebase
2. Explore the problem briefly
3. Create a change (the container for our work)
4. Build the artifacts: proposal → specs → design → tasks
5. Implement the tasks
6. Archive the completed change

**Time:** ~15-20 minutes

Let's start by finding something to work on.
```

---

## Phase 2: Task Suggestions

```
## Task Suggestions

Based on scanning your codebase, here are some good starter tasks:

**1. [Most promising task]**
   Location: `src/path/to/file.ts:42`
   Scope: ~1-2 files, ~20-30 lines
   Why it's good: [brief reason]

**2. [Second task]**
   Location: `src/another/file.ts`
   Scope: ~1 file, ~15 lines
   Why it's good: [brief reason]

**3. [Third task]**
   Location: [location]
   Scope: [estimate]
   Why it's good: [brief reason]

**4. Something else?**
   Tell me what you'd like to work on.

Which task interests you? (Pick a number or describe your own)
```

---

## Phase 2b: Scope Guardrail

```
That's a valuable task, but it's probably larger than ideal for your first OpenSpec run-through.

For learning the workflow, smaller is better—it lets you see the full cycle without getting stuck in implementation details.

**Options:**
1. **Slice it smaller** - What's the smallest useful piece of [their task]? Maybe just [specific slice]?
2. **Pick something else** - One of the other suggestions, or a different small task?
3. **Do it anyway** - If you really want to tackle this, we can. Just know it'll take longer.

What would you prefer?
```

---

## Phase 3: Quick Exploration

```
## Quick Exploration

[Your brief analysis—what you found, any considerations]

┌─────────────────────────────────────────┐
│   [Optional: ASCII diagram if helpful]  │
└─────────────────────────────────────────┘

Explore mode (`/opsx-explore`) is for this kind of thinking—investigating before implementing. You can use it anytime you need to think through a problem.

Now let's create a change to hold our work.
```

---

## Phase 4: Creating a Change

```
## Creating a Change

A "change" in OpenSpec is a container for all the thinking and planning around a piece of work. It lives in `openspec/changes/<name>/` and holds your artifacts—proposal, specs, design, tasks.

Let me create one for our task.
```

After creating the change, show:

```
Created: `openspec/changes/<name>/`

The folder structure:
openspec/changes/<name>/
├── proposal.md    ← Why we're doing this (empty, we'll fill it)
├── design.md      ← How we'll build it (empty)
├── specs/         ← Detailed requirements (empty)
└── tasks.md       ← Implementation checklist (empty)

Now let's fill in the first artifact—the proposal.
```

---

## Phase 5: The Proposal

```
## The Proposal

The proposal captures **why** we're making this change and **what** it involves at a high level. It's the "elevator pitch" for the work.

I'll draft one based on our task.
```

After saving:

```
Proposal saved. This is your "why" document—you can always come back and refine it as understanding evolves.

Next up: specs.
```

---

## Phase 6: Specs

```
## Specs

Specs define **what** we're building in precise, testable terms. They use a requirement/scenario format that makes expected behavior crystal clear.

For a small task like this, we might only need one spec file.
```

---

## Phase 7: Design

```
## Design

The design captures **how** we'll build it—technical decisions, tradeoffs, approach.

For small changes, this might be brief. That's fine—not every change needs deep design discussion.
```

---

## Phase 8: Tasks

```
## Tasks

Finally, we break the work into implementation tasks—checkboxes that drive the apply phase.

These should be small, clear, and in logical order.
```

---

## Phase 9: Implementation

```
## Implementation

Now we implement each task, checking them off as we go. I'll announce each one and occasionally note how the specs/design informed the approach.
```

After all tasks complete:

```
## Implementation Complete

All tasks done:
- [x] Task 1
- [x] Task 2
- [x] ...

The change is implemented! One more step—let's archive it.
```

---

## Phase 10: Archiving

```
## Archiving

When a change is complete, we archive it. This moves it from `openspec/changes/` to `openspec/changes/archive/YYYY-MM-DD-<name>/`.

Archived changes become your project's decision history—you can always find them later to understand why something was built a certain way.
```

After archiving:

```
Archived to: `openspec/changes/archive/YYYY-MM-DD-<name>/`

The change is now part of your project's history. The code is in your codebase, the decision record is preserved.
```

---

## Phase 11: Congratulations

```
## Congratulations!

You just completed a full OpenSpec cycle:

1. **Explore** - Thought through the problem
2. **New** - Created a change container
3. **Proposal** - Captured WHY
4. **Specs** - Defined WHAT in detail
5. **Design** - Decided HOW
6. **Tasks** - Broke it into steps
7. **Apply** - Implemented the work
8. **Archive** - Preserved the record

This same rhythm works for any size change—a small fix or a major feature.

---

## Command Reference

| Command | What it does |
|---------|--------------|
| `/opsx-explore` | Think through problems before/during work |
| `/opsx-new` | Start a new change, step through artifacts |
| `/opsx-ff` | Fast-forward: create all artifacts at once |
| `/opsx-continue` | Continue working on an existing change |
| `/opsx-apply` | Implement tasks from a change |
| `/opsx-verify` | Verify implementation matches artifacts |
| `/opsx-archive` | Archive a completed change |

---

## What's Next?

Try `/opsx-new` or `/opsx-ff` on something you actually want to build. You've got the rhythm now!
```

---

## Graceful Exit: Mid-Workflow Stop

```
No problem! Your change is saved at `openspec/changes/<name>/`.

To pick up where we left off later:
- `/opsx-continue <name>` - Resume artifact creation
- `/opsx-apply <name>` - Jump to implementation (if tasks exist)

The work won't be lost. Come back whenever you're ready.
```

---

## Graceful Exit: Quick Reference Request

```
## OpenSpec Quick Reference

| Command | What it does |
|---------|--------------|
| `/opsx-explore` | Think through problems (no code changes) |
| `/opsx-new <name>` | Start a new change, step by step |
| `/opsx-ff <name>` | Fast-forward: all artifacts at once |
| `/opsx-continue <name>` | Continue an existing change |
| `/opsx-apply <name>` | Implement tasks |
| `/opsx-verify <name>` | Verify implementation |
| `/opsx-archive <name>` | Archive when done |

Try `/opsx-new` to start your first change, or `/opsx-ff` if you want to move fast.
```
