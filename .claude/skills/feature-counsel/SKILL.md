---
name: feature-counsel
description: Analyze a project's OpenSpec from 8 persona perspectives and suggest additional features
---

# Feature Counsel — Multi-Persona Feature Advisory

Analyze a project's OpenSpec specifications from 8 different persona perspectives to identify missing features, usability gaps, and improvement opportunities.

**Input**: Optional argument after `/feature-counsel`:
- No argument → ask which project to analyze
- Project name → analyze that project directly (e.g., `opencatalogi`, `openregister`)

**Available projects**: Any directory under apps-extra with an `openspec/` folder.

---

## Personas

The Feature Counsel uses 8 personas representing the full spectrum of Dutch public sector users. Each persona card is stored in `hydra/personas/`:

| Persona | File | Perspective |
|---------|------|-------------|
| Henk Bakker | `henk-bakker.md` | Elderly citizen (78) — low digital skills, accessibility, plain Dutch |
| Fatima El-Amrani | `fatima-el-amrani.md` | Low-literate migrant (52) — visual design, simplicity, inclusivity |
| Sem de Jong | `sem-de-jong.md` | Young digital native (22) — performance, keyboard shortcuts, modern UX |
| Noor Yilmaz | `noor-yilmaz.md` | Municipal CISO (36) — security, audit trails, BIO2/ENSIA compliance |
| Annemarie de Vries | `annemarie-de-vries.md` | VNG standards architect (38) — GEMMA, Common Ground, NLGov API rules |
| Mark Visser | `mark-visser.md` | MKB software vendor (48) — business efficiency, CRUD, Dutch terminology |
| Priya Ganpat | `priya-ganpat.md` | ZZP developer (34) — API quality, DX, OpenAPI specs, integration |
| Jan-Willem van der Berg | `janwillem-van-der-berg.md` | Small business owner (55) — plain language, no jargon, simple workflows |

---

## Steps

### Step 0: Determine the Project

If no project was provided as argument, use AskUserQuestion to ask:

**"Which project would you like the Feature Counsel to analyze?"**

List the available projects by checking which directories have `openspec/` folders:
```
Available projects:
- opencatalogi
- openregister
- pipelinq
- procest
```

Store the chosen project as `{PROJECT}`.

### Step 1: Read the Project's Specs

Read the following files to understand the project:

1. `{PROJECT}/project.md` — Project context, architecture, purpose
2. `{PROJECT}/openspec/specs/` — All spec files (the main specifications)
3. `{PROJECT}/openspec/changes/` — Any active changes (proposals, delta specs)
4. `openspec/specs/` — Shared cross-project specs (api-patterns, nl-design, nextcloud-app)

Build a mental model of:
- What the project does
- Who it's for
- What features exist
- What APIs are exposed
- What the UI looks like
- What standards it must comply with

### Step 1.5: Select Agent Model

Ask the user using AskUserQuestion:

**"Which model should the persona agents use?"**

| Model | Speed | Quota | Best for |
|---|---|---|---|
| **Haiku** | Fastest | Low | Parallel runs — broad coverage, efficient |
| **Sonnet** | Balanced | Moderate | Better reasoning, more nuanced findings |
| **Opus** | Slowest | High | Deepest analysis — for critical or final runs |

- **Sonnet (default)** — Recommended. No browser snapshots involved — agents read specs and docs only, so context window size is not a concern. Better reasoning produces more useful feature suggestions.
- **Haiku** — Faster, lower quota. Use when you want a quick broad pass rather than depth.
- **Opus** — Highest quality analysis. With 8 agents running in parallel this uses substantial quota — best reserved for final pre-release testing or targeted critical reviews.

Store as `{MODEL}`:
- Haiku → `"haiku"`
- Sonnet → `"sonnet"`
- Opus → `"opus"`

### Step 2: Launch Persona Agents in Parallel

Launch 8 Task agents in parallel (all in a single message), one per persona. Each agent evaluates the specs from their persona's perspective. Use `subagent_type: "general-purpose"` and `model: "{MODEL}"` (from Step 1.5).

**Sub-agent prompt template** (replace `{PERSONA_NAME}`, `{PERSONA_FILE}`, `{PROJECT}`, and `{PERSPECTIVE}`):

```
You are a Feature Counsel advisor representing **{PERSONA_NAME}**.

## Your Persona
Read the persona card at `hydra/personas/{PERSONA_FILE}` to understand your character's background, digital skills, frustrations, needs, and behavior. Stay fully in character.

## Your Task
Analyze the OpenSpec specifications of the **{PROJECT}** project and suggest features, improvements, or changes from your persona's perspective.

## Read These Files
1. `{PROJECT}/project.md` — Project context
2. All files in `{PROJECT}/openspec/specs/` — Current specifications
3. All files in `{PROJECT}/openspec/changes/` — Active changes (if any)
4. Relevant shared specs in `openspec/specs/` (api-patterns, nl-design, nextcloud-app)

## Analysis Focus: {PERSPECTIVE}

For each spec section, ask yourself:
- Does this feature serve my needs as {PERSONA_NAME}?
- What's missing that I would need?
- What would frustrate me about this design?
- What would make this easier/better/more compliant for people like me?

## Output Format

Return your analysis as a structured report:

```markdown
# Feature Counsel: {PERSONA_NAME} — {one-line role description}

## Overall Assessment
{2-3 sentences from your persona's perspective on whether this project serves your needs}

## Missing Features (things I need that aren't in the specs)
| # | Feature | Priority | Why I need this | Spec section affected |
|---|---------|----------|-----------------|----------------------|
| 1 | {feature name} | MUST/SHOULD/COULD | "{persona perspective}" | {which spec} |

## Improvement Suggestions (existing features that need enhancement)
| # | Current Feature | Suggested Improvement | Why | Spec section |
|---|----------------|----------------------|-----|-------------|
| 1 | {feature} | {improvement} | "{persona perspective}" | {spec} |

## Compliance/Standards Gaps (from my perspective)
| # | Gap | Standard/Requirement | Impact | Recommendation |
|---|-----|---------------------|--------|----------------|
| 1 | {gap} | {standard} | HIGH/MEDIUM/LOW | {recommendation} |

## Usability Concerns
| # | Concern | Severity | {PERSONA_NAME} would say... |
|---|---------|----------|----------------------------|
| 1 | {concern} | HIGH/MEDIUM/LOW | "{in-character quote}" |

## {PERSONA_NAME}'s Top 3 Recommendations
1. {most important suggestion with rationale}
2. {second most important}
3. {third most important}
```
```

**Agent assignments:**

| Agent | Persona | Perspective Focus |
|-------|---------|-------------------|
| 1 | Henk Bakker | Accessibility, readability, plain Dutch, elderly-friendly design, text size, button size, simple navigation |
| 2 | Fatima El-Amrani | Visual design, literacy barriers, icon usage, mobile-first, minimal text, RTL support, B1 language level |
| 3 | Sem de Jong | Performance, keyboard shortcuts, dark mode, loading states, modern UX patterns, URL state, developer console |
| 4 | Noor Yilmaz | Security features, audit trails, RBAC, BIO2 compliance, ENSIA readiness, AVG/GDPR, data minimization |
| 5 | Annemarie de Vries | GEMMA alignment, Common Ground 5-layer, NLGov API Design Rules, interoperability, publiccode.yml, FSC |
| 6 | Mark Visser | Business efficiency, CRUD workflows, Dutch business terminology, status indicators, bulk operations, export |
| 7 | Priya Ganpat | API quality, OpenAPI spec, developer experience, error handling, pagination, webhooks, integration readiness |
| 8 | Jan-Willem van der Berg | Plain language, no jargon, simple forms, findability, contact info, 3-click rule, Dutch B1 level |

### Step 3: Synthesize the Results

After all 8 agents complete, read their reports and create a synthesized Feature Counsel report.

**Write the synthesis to**: `{PROJECT}/openspec/feature-counsel-report.md`

```markdown
# Feature Counsel Report: {PROJECT}

**Date:** {today's date}
**Method:** 8-persona feature advisory analysis against OpenSpec specifications
**Personas:** Henk Bakker, Fatima El-Amrani, Sem de Jong, Noor Yilmaz, Annemarie de Vries, Mark Visser, Priya Ganpat, Jan-Willem van der Berg

---

## Executive Summary

{3-5 sentences summarizing the overall findings across all personas}

---

## Consensus Features (suggested by 3+ personas)

| # | Feature | Suggested by | Priority | Impact |
|---|---------|-------------|----------|--------|
| 1 | {feature} | {persona names} | MUST/SHOULD/COULD | {why it matters} |

---

## Per-Persona Highlights

### Henk Bakker (Elderly Citizen)
- **Top need**: {one-liner}
- **Key missing feature**: {feature}
- **Quote**: "{in-character Dutch quote}"

### Fatima El-Amrani (Low-Literate Migrant)
...{repeat for all 8}

---

## Feature Suggestions by Category

### Accessibility & Inclusivity
| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | {feature} | {who suggested} | {priority} | {details} |

### Security & Compliance
| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | {feature} | {who suggested} | {priority} | {BIO2/AVG/etc} |

### API & Developer Experience
| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | {feature} | {who suggested} | {priority} | {details} |

### UX & Performance
| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | {feature} | {who suggested} | {priority} | {details} |

### Standards & Interoperability
| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | {feature} | {who suggested} | {priority} | {GEMMA/NLGov/etc} |

### Business & Workflow
| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | {feature} | {who suggested} | {priority} | {details} |

---

## Recommended Actions

### MUST (blocking for key user groups)
1. {action + rationale}

### SHOULD (significant improvement for multiple personas)
1. {action + rationale}

### COULD (nice-to-have, improves specific persona experience)
1. {action + rationale}

---

## Potential OpenSpec Changes

These features could be turned into OpenSpec changes using `/opsx-new`:

| Change Name | Description | Related Personas | Estimated Complexity |
|-------------|-------------|-----------------|---------------------|
| {change-name} | {description} | {personas} | S/M/L/XL |
```

### Step 4: Report to User

Display a concise summary to the user:
- Number of features suggested across all personas
- Top consensus features (suggested by 3+ personas)
- Top 5 recommended actions
- Link to the full report: `{PROJECT}/openspec/feature-counsel-report.md`
- Offer to create OpenSpec changes for any of the suggested features

---

## Capture Learnings

After execution, review what happened and append new observations to [learnings.md](learnings.md) under the appropriate section:

- **Patterns That Work** — approaches that produced good results
- **Mistakes to Avoid** — errors encountered and how they were resolved
- **Domain Knowledge** — facts discovered during this run
- **Open Questions** — unresolved items for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.
