# Comment Format Rules

## Severity System

| Emoji | Label | When to use |
|-------|-------|-------------|
| 🔴 | Blocker | Prevents merge: regression, wrong behavior, claim contradicted by code |
| 🟡 | Concern | Non-blocking but worth addressing before or soon after merge |
| 🟢 | Minor | Nit, style, follow-up issue, future-proofing suggestion |

## Comment Body Format

```markdown
### 🔴 Blocker — <short title>

<one-sentence problem statement>

**Impact:** <who/what is affected and how>

**Suggested fix:** <concrete code or approach>
```

```markdown
🟡 **Concern — <short title>**

<explanation>
```

```markdown
🟢 **Minor — <short title>**

<observation and optional suggestion>
```

## Placement Rules

- One comment per finding — never combine two separate issues in one comment.
- Blockers: use the `### 🔴` heading format (prominent).
- Concerns: use bold inline `🟡 **Concern — title**` format.
- Minor: use bold inline `🟢 **Minor — title**` format.
- Place the comment on the most directly relevant line in the diff:
  - Prefer the line where the bug is introduced over the line where it manifests.
  - If the issue is about deleted code, comment on the pointer/comment that replaced it.
  - If the issue is about missing code (e.g., a missing null guard), comment on the
    nearest related changed line and name the missing location in the body.

## Mode-Aware Body Length

| Mode | Body style |
|------|-----------|
| Quick | Skip concerns and minors entirely; blockers: full body (problem + suggested fix) — must be clear enough for the author to resolve |
| Standard | One paragraph max per comment; state problem + fix |
| Thorough | Full body: problem statement + **Impact:** + **Suggested fix:** |
| Strict | Full body: name exact risk category + **Impact:** + **Suggested fix:** |

The 🟡 Concern and 🟢 Minor templates above are the **Standard** baseline. Expand to include
`**Impact:**` and `**Suggested fix:**` sections in Thorough/Strict mode. In Quick mode,
skip concerns and minors entirely — but any blocker still requires a full, actionable body.

## Overall Review Body (Step 7)

One or two sentences maximum. State the verdict and the reason:

- **REQUEST_CHANGES**: "N blockers require fixes before merge — [brief reason]. [What checks out]."
- **APPROVE**: "No blockers found. [Any notable observations or what was verified]."

Do not repeat individual findings in the overall body — those are in the inline comments.
