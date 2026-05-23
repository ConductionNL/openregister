---
name: opsx-ff
description: Create a change and generate all artifacts needed for implementation in one go (Blocks Haiku)
metadata:
  category: Workflow
  tags: [workflow, artifacts, experimental]
---

**Step 0: Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — generating proposal/specs/design/tasks artifacts in one pass needs stronger reasoning than Haiku can reliably provide. Please switch to Sonnet (`/model sonnet`) or Opus (`/model opus`) and re-run."
- **On Sonnet or Opus**: proceed normally.

---

Fast-forward through artifact creation - generate everything needed to start implementation.

**Input**: The argument after `/opsx-ff` is the change name (kebab-case), OR a description of what the user wants to build.

**Steps**

1. **If no input provided, ask what they want to build**

   Use the **AskUserQuestion tool** (open-ended, no preset options) to ask:
   > "What change do you want to work on? Describe what you want to build or fix."

   From their description, derive a kebab-case name (e.g., "add user authentication" → `add-user-auth`).

   **IMPORTANT**: Do NOT proceed without understanding what the user wants to build.

1.5. **Confirm the plan before generating**

   Summarize your understanding and use **AskUserQuestion** to confirm before doing any work:

   > "I'll create a change called `<name>` to: <one-sentence summary of what the user wants to build>. Ready to generate all artifacts?"

   Options:
   - **Yes, generate all artifacts** — proceed to Step 2
   - **Let me clarify something first** — ask a targeted follow-up question, then re-confirm before continuing

   **Do NOT create any files until confirmed.**

1.55. **Select model for artifact generation**

   This skill generates OpenSpec artifacts (proposal, specs, design, tasks) — the quality of these artifacts determines implementation quality downstream.

   Ask the user using AskUserQuestion:

   **"Which model should I use for artifact generation?"**

   | Model | Pros | Cons |
   |---|---|---|
   | **Sonnet (recommended)** | Good artifact quality, moderate quota | Solid for most changes |
   | **Opus** | Best design and architectural reasoning | Uses more quota — worth it for complex or architectural changes |

   - **Sonnet**
   - **Opus**

   Use the **Agent tool** with `model: "sonnet"` or `model: "opus"` (whichever was selected) to delegate Steps 2–4. Pass the subagent:
   - The change name
   - Full contents of any app design files loaded in Step 1.6
   - The complete instructions for Steps 2–4 from this skill
   - Instruction: return a `DEFERRED_QUESTIONS` list at the end of its output — one entry per decision made under uncertainty (see Step 4c)

   When the subagent completes:
   - **MANDATORY**: If the subagent returned ANY `DEFERRED_QUESTIONS`, you MUST ask the user EVERY question — no exceptions. Do NOT evaluate, triage, or skip questions yourself. Do NOT conclude "no user input needed" for any question. The subagent deferred these questions precisely because they require human judgment.
   - For each deferred question, use **AskUserQuestion** to present:
     1. The question the subagent would have asked
     2. The provisional decision the subagent made
     3. Which artifact it affects
   - Ask one question at a time. Wait for the user's answer before asking the next.
   - After each answer: re-read the relevant artifact. If the user's answer differs from the provisional decision, update the artifact. Show "✎ Updated <artifact-id>" or "✓ Kept as-is" based on the user's explicit confirmation.
   - Then continue to Step 5.

1.6. **Load app design context (if present)**

   Before creating any artifacts, check for and silently load app design documents. These inform proposal scope, architecture constraints, and applicable ADRs — the `openspec instructions` context does not include them.

   | File | If present, use to... |
   |------|----------------------|
   | `openspec/changes/<name>/context-brief.md` | **Specter intelligence brief** — full features, user stories, stakeholders, schemas, standards, ADRs. This is the PRIMARY input when present — read it fully and use its data for all artifacts |
   | `openspec/architecture/` | **Repo-specific ADRs** (the only architecture source in app repos). Constrain the implementation approach. Authored by Specter during research; evolved by humans. |
   | hydra `openspec/architecture/` (org-wide) | **Company-wide ADRs apply to every app**. Live ONLY in the [hydra repo](https://github.com/ConductionNL/hydra/tree/development/openspec/architecture). Reviewer + builder containers copy the relevant subset in at image-build time; `/pr-context/` surfaces anything the stage needs. App repos do not carry local copies — see hydra/CLAUDE.md "ADR ownership". |
   | `docs/ARCHITECTURE.md` | Understand app-specific technology decisions and data model |
   | `docs/FEATURES.md` | Confirm the feature tier and roadmap phase for what is being built |

   If a `context-brief.md` exists, it contains market-researched features with demand scores, real user stories with acceptance criteria, stakeholder profiles with pain points, and full data model schemas. Use this data directly in artifacts — do not invent features or stories when the brief provides them.

   If none of these files exist beyond the standard ADRs, proceed silently — do not block or prompt the user.

2. **Create the change directory**
   ```bash
   openspec new change "<name>"
   ```
   This creates a scaffolded change at `openspec/changes/<name>/`.

3. **Get the artifact build order**
   ```bash
   openspec status --change "<name>" --json
   ```
   Parse the JSON to get:
   - `applyRequires`: array of artifact IDs needed before implementation (e.g., `["tasks"]`)
   - `artifacts`: list of all artifacts with their status and dependencies

4. **Create artifacts in sequence until apply-ready**

   Use the **TodoWrite tool** to track progress through the artifacts.

   Loop through artifacts in dependency order (artifacts with no pending dependencies first):

   a. **For each artifact that is `ready` (dependencies satisfied)**:

      **Skip check**: Read the artifact's entry in the schema (`openspec schema which conduction` to find path, then read `schema.yaml`). If the artifact has `optional: true`, evaluate its `skipWhen` condition against the proposal (and design, if available). If the condition is met, show "⊘ Skipped <artifact-id> — <reason>" and move to the next artifact. Do NOT create stub files for skipped artifacts.

      **If not skipped**, get instructions:
        ```bash
        openspec instructions <artifact-id> --change "<name>" --json
        ```
      - The instructions JSON includes:
        - `context`: Project background (constraints for you - do NOT include in output)
        - `rules`: Artifact-specific rules (constraints for you - do NOT include in output)
        - `template`: The structure to use for your output file
        - `instruction`: Schema-specific guidance for this artifact type
        - `outputPath`: Where to write the artifact
        - `dependencies`: Completed artifacts to read for context
      - Read any completed dependency files for context
      - Create the artifact file using `template` as the structure
      - Apply `context` and `rules` as constraints - but do NOT copy them into the file
      - Show brief progress: "✓ Created <artifact-id>"

   b. **Continue until all `applyRequires` artifacts are complete**
      - After creating each artifact (or skipping it), re-run `openspec status --change "<name>" --json`
      - Check if every artifact ID in `applyRequires` has `status: "done"` in the artifacts array
      - Stop when all `applyRequires` artifacts are done
      - Skipped optional artifacts will remain as `ready` in the status — this is expected

   c. **If an artifact requires user input** (unclear context):
      - Make a reasonable decision and continue — AskUserQuestion is not available inside a subagent
      - Add an entry to `DEFERRED_QUESTIONS`: the question you would have asked, the decision you made, and which artifact it affected
      - Return the full `DEFERRED_QUESTIONS` list at the end of your output so the parent can follow up with the user

5. **Show final status**
   ```bash
   openspec status --change "<name>"
   ```

**Output**

After completing all artifacts, summarize:
- Change name and location
- List of artifacts created with brief descriptions
- What's ready: "All artifacts created! Ready for implementation."

**What's Next**

**Recommended:** `/opsx-apply` — start implementing the tasks

**Optional before that:**
- `/opsx-plan-to-issues` — create GitHub Issues for progress tracking

**Spec maintenance:** After creating artifacts, check the proposal's `## Capabilities` section. For each capability listed under "Modified Capabilities" or "New Capabilities", find (or create) the corresponding spec at `openspec/specs/<capability>/spec.md` and:
- Add this change to the `**OpenSpec changes**` list (as a new line, after any existing entries, oldest-first ordering)
- Set `**Status**: in-progress` if it was `planned` or `done` — a new active change always moves the spec back to `in-progress`
- If the list exceeds 15 entries, apply the grouping rule from [writing-specs.md](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-specs.md) (group by timeframe, never remove entries)

**Artifact Creation Guidelines**

- Follow the `instruction` field from `openspec instructions` for each artifact type
- The schema defines what each artifact should contain - follow it
- Read dependency artifacts for context before creating new ones
- Use the `template` as a starting point, filling in based on context
- **design.md MUST include a Seed Data section** (ADR-001): research realistic objects per schema with general organization data (municipality, consultancy, travel agency). The apply agent generates `_registers.json` entries from this section
- **tasks.md MUST include a seed data task** when the change introduces or modifies OpenRegister schemas
- **design.md MUST include a "Declarative-vs-imperative decision" section** (ADR-031) when the change introduces or modifies behaviour matching any of: lifecycle / state machines / transitions, aggregations / summaries / counts, derived or virtual fields, notifications / alerts, declarative relations between OR objects, or dashboard widgets. The default path is **declarative** — declared as `x-openregister-{lifecycle, aggregations, calculations, notifications, relations, widgets}` in the app's `lib/Settings/{app}_register.json` schema register, NOT as a new `lib/Service/*Service.php` class. The section lists each behaviour, the chosen path, and the rationale (or the exception per ADR-031 if imperative is justified — external integration, document generation, NLP, domain rule selector, lifecycle guard, or scheduled bulk work that genuinely needs `ScheduledWorkflow` + n8n rather than a derived field)
- **tasks.md MUST land declarative behaviours as schema-register patches** (e.g. "Add `x-openregister-lifecycle` to `<Schema>` in `lib/Settings/{app}_register.json`") rather than as new service-class tasks. Reference example: `decidesk/lib/Settings/decidesk_register.json` (Meeting/Motion/Amendment lifecycles, ActionItem aggregations + calculations, Meeting/Decision notifications)
- **proposal.md MUST declare `kind:` in frontmatter** (per ADR-032): one of `config` (only declarative JSON edits — schema register, manifest, OpenAPI), `code` (PHP / Vue / TS / etc.), or — if both surfaces are touched — split first. `mixed` proposals are an anti-pattern: the 2026-05-07 Stage A run showed two `mixed` specs (quorum + analytics) burned the full 200-turn Sonnet builder budget without producing a PR. Before generating any artifacts for a `mixed`-shaped description, opsx-ff MUST offer to split into a chain (`{slug}-schema-declaration` config spec → `{slug}-{consumer}-rewrite` code spec → `{slug}-{obsolete}-deletion` code spec) where each subsequent spec lists predecessors in `depends_on`. Hydra's supervisor blocks dependent specs from building until each named dep's issue is closed (merged), so chains run in correct order automatically. Thin-glue exception: a `mixed` spec is permitted when the code change is ≤20 LOC across ≤2 files AND tightly coupled to the config change; document the coupling in design.md under "Mixed-spec rationale" (still a yellow flag in review).
- **proposal.md MUST declare `depends_on:` in frontmatter** when the spec is part of a chain. Format: list of spec slugs (translated to issue numbers at plan-to-issues time). For `kind: config` specs that are the head of a chain, narrate the planned chain in the proposal body so reviewers understand the full migration arc.

### Safe example values for API keys, UUIDs, tokens, secrets

Specs, design docs, and schemas regularly show example JSON / cURL / code with placeholder values for API keys, client UUIDs, auth tokens, webhook secrets, connection strings, etc. These land in the repo as plain markdown — which means the downstream security review runs `gitleaks detect` against them. Gitleaks' `generic-api-key` rule matches any string that *looks* high-entropy. If the example value looks like a real secret, gitleaks flags it as a leaked secret, the reviewer's findings get polluted with false positives, and real signals get lost.

**Always use values that obviously mark themselves as placeholders. Never use values that could plausibly be real credentials.**

| Category | ✅ Safe (gitleaks ignores) | ❌ Avoid (gitleaks flags as `generic-api-key`) |
|---|---|---|
| API keys / tokens | `YOUR_API_KEY_HERE`, `xxx-your-token-xxx`, `<API_KEY>`, `sk-REPLACE_ME` | `abc123-my-api-key`, `sk-1a2b3c4d5e6f7890`, any random-looking hex |
| UUIDs | `00000000-0000-0000-0000-000000000000` (nil UUID), `<client-uuid>`, `{uuid}` | `f47ac10b-58cc-4372-a567-0e02b2c3d479` (RFC example), any realistic-looking UUID |
| Passwords | `CHANGE_ME`, `<PASSWORD>`, `hunter2` (famously known placeholder) | Anything entropic |
| Bearer tokens | `Bearer YOUR_TOKEN_HERE` | `Bearer eyJhbGci...` even if truncated |
| Webhook secrets | `WEBHOOK_SECRET_HERE` | `whsec_` + random hex |
| Connection strings | `postgres://user:PASS@host:5432/db` (PASS in caps) | `postgres://admin:S3cr3tP@ss@host/db` |

The rule of thumb: **if a reader might wonder "is this a real value someone forgot to redact?", rewrite it.** Uppercase placeholder text, `<angle-brackets>`, or the nil UUID are the three safest forms.

This matters especially in `specs/<feature>/spec.md`, `design.md`, and `context-brief.md` — documents that routinely include API example requests and get copy-pasted into implementation code later.

## Capture Learnings

After artifacts are created, review what happened and append any new observations to [learnings.md](learnings.md):

- **Patterns That Work** — artifact generation approaches that produce high-quality, implementation-ready output
- **Mistakes to Avoid** — artifact generation errors, underspecified decisions, or deferred question pitfalls
- **Domain Knowledge** — facts about OpenSpec artifact schemas, dependency ordering, or generation patterns
- **Open Questions** — unresolved artifact quality challenges for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

---

**Guardrails**
- Create ALL artifacts needed for implementation (as defined by schema's `apply.requires`)
- Always read dependency artifacts before creating a new one
- If context is critically unclear, ask the user - but prefer making reasonable decisions to keep momentum
- **NEVER skip deferred questions from the subagent** — every `DEFERRED_QUESTIONS` entry MUST be presented to the user via AskUserQuestion, regardless of how reasonable the subagent's provisional decision seems
- If a change with that name already exists, ask if user wants to continue it or create a new one
- Verify each artifact file exists after writing before proceeding to next
