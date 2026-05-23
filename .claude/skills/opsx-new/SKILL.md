---
name: opsx-new
description: Start a new change using the experimental artifact workflow (OPSX)
metadata:
  category: Workflow
  tags: [workflow, artifacts, experimental]
---

Start a new change using the experimental artifact-driven approach.

**Input**: The argument after `/opsx-new` is the change name (kebab-case), OR a description of what the user wants to build.

**Steps**

1. **If no input provided, ask what they want to build**

   Use the **AskUserQuestion tool** (open-ended, no preset options) to ask:
   > "What change do you want to work on? Describe what you want to build or fix."

   From their description, derive a kebab-case name (e.g., "add user authentication" → `add-user-auth`).

   **IMPORTANT**: Do NOT proceed without understanding what the user wants to build.

2. **Determine the workflow schema**

   Use the default schema (omit `--schema`) unless the user explicitly requests a different workflow.

   **Use a different schema only if the user mentions:**
   - A specific schema name → use `--schema <name>`
   - "show workflows" or "what workflows" → run `openspec schemas --json` and let them choose

   **Otherwise**: Omit `--schema` to use the default.

3. **Load architectural context**

   Before creating the change, silently read all applicable ADRs. These inform what is architecturally possible and constrain the proposal scope.

   | Location | What | When |
   |----------|------|------|
   | `openspec/changes/<name>/context-brief.md` | Specter intelligence brief — features, stories, stakeholders, schemas | If present — this is the primary input |
   | `openspec/architecture/adr-*.md` | Repo-specific ADRs (data model, workflows, security) | Always check |
   | `../../../openspec/architecture/adr-*.md` (relative to skill base dir) | Company-wide ADRs (17 Conduction-wide decisions) — these live in the Hydra repo that contains this skill | Always load |
   | `docs/ARCHITECTURE.md` | App-specific technology decisions | If present |
   | `docs/FEATURES.md` | Feature tiers and roadmap phases | If present |

   Read these silently — do not list them to the user. If a `context-brief.md` exists for this change, it contains market-researched intelligence data. Use it as the primary source for the change description — do not ask the user what to build when the brief already describes it.

   Use them to inform the change name validation (e.g., if the user asks for something that contradicts an ADR, flag it).

4. **Create the change directory**
   ```bash
   openspec new change "<name>"
   ```
   Add `--schema <name>` only if the user requested a specific workflow.
   This creates a scaffolded change at `openspec/changes/<name>/` with the selected schema.

5. **Show the artifact status**
   ```bash
   openspec status --change "<name>"
   ```
   This shows which artifacts need to be created and which are ready (dependencies satisfied).

6. **Get instructions for the first artifact**
   The first artifact depends on the schema. Check the status output to find the first artifact with status "ready".
   ```bash
   openspec instructions <first-artifact-id> --change "<name>"
   ```
   This outputs the template and context for creating the first artifact.

7. **Ask how to proceed**

   After showing the status and first artifact template, use **AskUserQuestion** to ask:

   > "Change `<name>` is ready. How would you like to proceed?"

   Options:
   - **Generate all artifacts at once** (`/opsx-ff <name>`) — fastest path to implementation; I'll create proposal, specs, design, and tasks in one go
   - **Create artifacts one at a time** (`/opsx-continue <name>`) — more control; you review and approve each artifact before the next
   - **Think through the problem first** (`/opsx-explore`) — explore ideas, constraints, and approach before committing to a direction
   - **Done for now** — come back later with `/opsx-continue <name>`

**Output**

After completing the steps, summarize:
- Change name and location
- Schema/workflow being used and its artifact sequence
- Current status (0/N artifacts complete)
- The template for the first artifact

**Guardrails**
- Do NOT create any artifacts yet - just show the instructions
- Do NOT advance beyond showing the first artifact template
- If the name is invalid (not kebab-case), ask for a valid name
- If a change with that name already exists, suggest using `/opsx-continue` instead
- Pass --schema if using a non-default workflow
