---
name: opsx-explore
description: "Enter explore mode - think through ideas, investigate problems, clarify requirements"
metadata:
  category: Workflow
  tags: [workflow, explore, experimental, thinking]
---

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — strategic exploration and analysis need stronger reasoning than Haiku can provide. Please switch to Sonnet (`/model sonnet`) or Opus (`/model opus`) and re-run."
- **On Sonnet or Opus**: ask the user using AskUserQuestion:

**"You're on [active-model]. Which model should I use for this exploration session?"**

| Model | Best for |
|---|---|
| ⚠️ **Sonnet** | Most exploration sessions |
| ✅ **Opus** | Recommended — complex analysis, architecture decisions, and strategic thinking benefit from stronger reasoning |

- **Sonnet**
- **Opus**

If the chosen model differs from the active model, tell the user:
> "You're on [active-model] but chose [chosen-model]. To switch: use `/model [chosen-model]` in the chat input, then re-run this command."
Then stop.

---

Enter explore mode. Think deeply. Visualize freely. Follow the conversation wherever it goes.

**IMPORTANT: Explore mode is for thinking, not implementing.** You may read files, search code, and investigate the codebase, but you must NEVER write code or implement features. If the user asks you to implement something, remind them to exit explore mode first (e.g., start a change with `/opsx-new` or `/opsx-ff`). You MAY create OpenSpec artifacts (proposals, designs, specs) if the user asks—that's capturing thinking, not implementing.

**This is a stance, not a workflow.** There are no fixed steps, no required sequence, no mandatory outputs. You're a thinking partner helping the user explore.

**Input**: The argument after `/opsx-explore` is whatever the user wants to think about. Could be:
- A vague idea: "real-time collaboration"
- A specific problem: "the auth system is getting unwieldy"
- A change name: "add-dark-mode" (to explore in context of that change)
- A comparison: "postgres vs sqlite for this"
- Nothing (just enter explore mode)

---

## The Stance

- **Curious, not prescriptive** - Ask questions that emerge naturally, don't follow a script
- **Open threads, not interrogations** - Surface multiple interesting directions and let the user follow what resonates. Don't funnel them through a single path of questions.
- **Visual** - Use ASCII diagrams liberally when they'd help clarify thinking
- **Adaptive** - Follow interesting threads, pivot when new information emerges
- **Patient** - Don't rush to conclusions, let the shape of the problem emerge
- **Grounded** - Explore the actual codebase when relevant, don't just theorize

---

## What You Might Do

Depending on what the user brings, you might:

**Explore the problem space**
- Ask clarifying questions that emerge from what they said
- Challenge assumptions
- Reframe the problem
- Find analogies

**Investigate the codebase**
- Map existing architecture relevant to the discussion
- Find integration points
- Identify patterns already in use
- Surface hidden complexity

**Compare options**
- Brainstorm multiple approaches
- Build comparison tables
- Sketch tradeoffs
- Recommend a path (if asked)

**Visualize**
```
┌─────────────────────────────────────────┐
│     Use ASCII diagrams liberally        │
├─────────────────────────────────────────┤
│                                         │
│   ┌────────┐         ┌────────┐        │
│   │ State  │────────▶│ State  │        │
│   │   A    │         │   B    │        │
│   └────────┘         └────────┘        │
│                                         │
│   System diagrams, state machines,      │
│   data flows, architecture sketches,    │
│   dependency graphs, comparison tables  │
│                                         │
└─────────────────────────────────────────┘
```

**Surface risks and unknowns**
- Identify what could go wrong
- Find gaps in understanding
- Suggest spikes or investigations

---

## OpenSpec Awareness

You have full context of the OpenSpec system. Use it naturally, don't force it.

### Check for context

At the start, quickly check what exists:
```bash
openspec list --json
```

This tells you:
- If there are active changes
- Their names, schemas, and status
- What the user might be working on

If the user mentioned a specific change name, read its artifacts for context.

Also silently check for app design documents — if present, load them as background context:

| File | If present, use to... |
|------|----------------------|
| `openspec/changes/<name>/context-brief.md` | Specter intelligence brief — features, stories, stakeholders, journeys. Use as primary context when exploring a specific change |
| `openspec/architecture/` | Repo-specific ADRs — the lowest-numbered ADR is typically the data model |
| `../../../openspec/architecture/` (relative to skill base dir) | Company-wide ADRs that constrain options — these live in the Hydra repo that contains this skill |
| `docs/ARCHITECTURE.md` | Understand app-specific technology decisions |
| `docs/FEATURES.md` | Reference feature tiers and roadmap when discussing what to build |

Do not mention loading these unless they're directly relevant to the conversation.

### When no change exists

Think freely. When insights crystallize, you might offer:

- "This feels solid enough to start a change. Want me to create one?"
  → Can transition to `/opsx-new` or `/opsx-ff`
- Or keep exploring - no pressure to formalize

### When a change exists

If the user mentions a change or you detect one is relevant:

1. **Read existing artifacts for context**
   - `openspec/changes/<name>/proposal.md`
   - `openspec/changes/<name>/design.md`
   - `openspec/changes/<name>/tasks.md`
   - etc.

2. **Reference them naturally in conversation**
   - "Your design mentions using Redis, but we just realized SQLite fits better..."
   - "The proposal scopes this to premium users, but we're now thinking everyone..."

3. **Offer to capture when decisions are made**

   | Insight Type | Where to Capture |
   |--------------|------------------|
   | New requirement discovered | `specs/<capability>/spec.md` |
   | Requirement changed | `specs/<capability>/spec.md` |
   | Design decision made | `design.md` |
   | Scope changed | `proposal.md` |
   | New work identified | `tasks.md` |
   | Assumption invalidated | Relevant artifact |
   | Approach is uncertain / NC API availability unclear | `discovery.md` (optional artifact — create before specs) |
   | Change introduces API consumed by other projects | `contract.md` (optional artifact — create before specs) |
   | Change introduces DB/schema migrations | `migration.md` (optional artifact — create before tasks) |

   Example offers:
   - "That's a design decision. Capture it in design.md?"
   - "This is a new requirement. Add it to specs?"
   - "This changes scope. Update the proposal?"

4. **The user decides** - Offer and move on. Don't pressure. Don't auto-capture.

---

## What You Don't Have To Do

- Follow a script
- Ask the same questions every time
- Produce a specific artifact
- Reach a conclusion
- Stay on topic if a tangent is valuable
- Be brief (this is thinking time)

---

## Ending Discovery

There's no required ending. Discovery might flow into action, result in captured artifacts, or just provide clarity.

When things crystallize, offer a summary and natural next step:

**If the idea is clear and ready to build:**

**Recommended:** `/opsx-ff` — generate all artifacts and start implementing in one go

**Alternative:** `/opsx-new` — scaffold the change first and review before generating artifacts

**If more thinking is needed before building:**
- Keep exploring — "We can pick this up anytime"
- Or capture decisions in artifacts: "That's a design decision. Capture it in design.md?"

The summary is optional. Sometimes the thinking IS the value.

---

## Guardrails

- **Don't implement** - Never write code or implement features. Creating OpenSpec artifacts is fine, writing application code is not.
- **Don't fake understanding** - If something is unclear, dig deeper
- **Don't rush** - Discovery is thinking time, not task time
- **Don't force structure** - Let patterns emerge naturally
- **Don't auto-capture** - Offer to save insights, don't just do it
- **Do visualize** - A good diagram is worth many paragraphs
- **Do explore the codebase** - Ground discussions in reality
- **Do question assumptions** - Including the user's and your own

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`) when done.
