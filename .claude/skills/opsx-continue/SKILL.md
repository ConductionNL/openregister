---
name: opsx-continue
description: Continue working on a change - create the next artifact (Experimental)
metadata:
  category: Workflow
  tags: [workflow, artifacts, experimental]
---

Continue working on a change by creating the next artifact.

**Input**: Optionally specify a change name after `/opsx-continue` (e.g., `/opsx-continue add-auth`). If omitted, check if it can be inferred from conversation context. If vague or ambiguous you MUST prompt for available changes.

**Steps**

1. **If no change name provided, prompt for selection**

   Run `openspec list --json` to get available changes sorted by most recently modified. Then use the **AskUserQuestion tool** to let the user select which change to work on.

   Present the top 3-4 most recently modified changes as options, showing:
   - Change name
   - Schema (from `schema` field if present, otherwise "spec-driven")
   - Status (e.g., "0/5 tasks", "complete", "no tasks")
   - How recently it was modified (from `lastModified` field)

   Mark the most recently modified change as "(Recommended)" since it's likely what the user wants to continue.

   **IMPORTANT**: Do NOT guess or auto-select a change. Always let the user choose.

1.5. **Load app design context (if present)**

   Before creating any artifact, check for and silently load app design documents. These inform proposal scope, architecture constraints, and applicable ADRs.

   | File | If present, use to... |
   |------|----------------------|
   | `openspec/changes/<name>/context-brief.md` | **Specter intelligence brief** — features, user stories, stakeholders, journeys, schemas. Primary input when present — read fully |
   | `openspec/architecture/` | Repo-specific ADRs — the lowest-numbered ADR is typically the data model |
   | `../../../openspec/architecture/` (relative to skill base dir) | Company-wide ADRs (always apply) — these live in the Hydra repo that contains this skill |
   | `docs/ARCHITECTURE.md` | Understand app-specific technology decisions and data model |
   | `docs/FEATURES.md` | Confirm the feature tier and roadmap phase for what is being built |

   If a `context-brief.md` exists, it contains market-researched intelligence data. Use it directly — do not invent features or stories when the brief provides them.

   If none of these files exist beyond the standard ADRs, proceed silently — do not block or prompt the user.

2. **Check current status**
   ```bash
   openspec status --change "<name>" --json
   ```
   Parse the JSON to understand current state. The response includes:
   - `schemaName`: The workflow schema being used (e.g., "spec-driven")
   - `artifacts`: Array of artifacts with their status ("done", "ready", "blocked")
   - `isComplete`: Boolean indicating if all artifacts are complete

3. **Act based on status**:

   ---

   **If all artifacts are complete (`isComplete: true`)**:
   - Congratulate the user
   - Show final status including the schema used
   - Show **What's Next**:

     **Recommended:** `/opsx-apply` — start implementing the tasks

     **Optional before that:**
     - `/opsx-plan-to-issues` — create GitHub Issues for progress tracking
   - STOP

   ---

   **If artifacts are ready to create** (status shows artifacts with `status: "ready"`):
   - First, determine which ready artifacts are **required** vs **optional**:
     - Build the required set by walking the dependency graph backwards from `applyRequires` — any artifact transitively needed to satisfy `apply.requires` is required; all others are optional
     - If any **required** artifacts are `status: "ready"`, pick the first one and proceed normally
     - If only **optional** artifacts are `status: "ready"` (all required artifacts are done), ask the user using AskUserQuestion:
       > "All required artifacts are done. These optional artifacts are available — would you like to create one before implementing?"
       Present each optional artifact with its id and description as a choice, plus "No — proceed to implementation (`/opsx-apply`)"
       Create whichever artifact the user selects, or stop if they choose to proceed.
   - Pick the selected artifact with `status: "ready"` from the status output
   - Get its instructions:
     ```bash
     openspec instructions <artifact-id> --change "<name>" --json
     ```
   - Parse the JSON. The key fields are:
     - `context`: Project background (constraints for you - do NOT include in output)
     - `rules`: Artifact-specific rules (constraints for you - do NOT include in output)
     - `template`: The structure to use for your output file
     - `instruction`: Schema-specific guidance
     - `outputPath`: Where to write the artifact
     - `dependencies`: Completed artifacts to read for context
   - **Create the artifact file**:
     - Read any completed dependency files for context
     - Use `template` as the structure - fill in its sections
     - Apply `context` and `rules` as constraints when writing - but do NOT copy them into the file
     - Write to the output path specified in instructions
   - Show what was created and what's now unlocked
   - STOP after creating ONE artifact

   ---

   **If no artifacts are ready (all blocked)**:
   - This shouldn't happen with a valid schema
   - Show status and suggest checking for issues

4. **After creating an artifact, show progress**
   ```bash
   openspec status --change "<name>"
   ```

**Output**

After each invocation, show:
- Which artifact was created
- Schema workflow being used
- Current progress (N/M complete)
- What artifacts are now unlocked

**What's Next**

**Recommended:** `/opsx-continue` — create the next artifact

**Alternative (skip ahead):** `/opsx-ff` — generate all remaining artifacts in one go

**Artifact Creation Guidelines**

The artifact types and their purpose depend on the schema. Use the `instruction` field from the instructions output to understand what to create.

Common artifact patterns:

**spec-driven schema** (proposal → specs → design → tasks):
- **proposal.md**: Ask user about the change if not clear. Fill in Why, What Changes, Capabilities, Impact.
  - The Capabilities section is critical - each capability listed will need a spec file.
  - **After creating proposal.md**: check the `## Capabilities` section. For each capability listed under "Modified Capabilities" or "New Capabilities", find (or create) the corresponding spec at `openspec/specs/<capability>/spec.md` and:
    - Add this change to the `**OpenSpec changes**` list (new entry at bottom, oldest-first ordering)
    - Set `**Status**: in-progress` if it was `planned` or `done` — a new active change always moves the spec back to `in-progress`
    - If the list exceeds 15 entries, apply the grouping rule from [writing-specs.md](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-specs.md) (group by timeframe, never remove entries)
- **specs/<capability>/spec.md**: Create one spec per capability listed in the proposal's Capabilities section (use the capability name, not the change name).
- **design.md**: Document technical decisions, architecture, and implementation approach. **MUST include a Seed Data section** (ADR-001) with realistic objects per schema — general organization data that works for municipalities, consultancies, or travel agencies. Research realistic field values. The apply agent generates `_registers.json` entries from this section.
- **tasks.md**: Break down implementation into checkboxed tasks. Include a task for generating seed data in `_registers.json` when the change introduces or modifies schemas.

For other schemas, follow the `instruction` field from the CLI output.

**Guardrails**
- Create ONE artifact per invocation
- Always read dependency artifacts before creating a new one
- Never skip artifacts or create out of order
- If context is unclear, ask the user before creating
- Verify the artifact file exists after writing before marking progress
- Use the schema's artifact sequence, don't assume specific artifact names
- **IMPORTANT**: `context` and `rules` are constraints for YOU, not content for the file
  - Do NOT copy `<context>`, `<rules>`, `<project_context>` blocks into the artifact
  - These guide what you write, but should never appear in the output
