# Skill Health Check — Standalone Audit (Phase 6 Part B)

Read every command and skill file independently and audit against the current state of the project. This is **not limited to what changed** in the current sync — it is a full content review.

## What to load as reference

- `{GITHUB_REPO}/docs/claude/writing-docs.md` — documentation principles (link vs duplicate, audience, anti-patterns)
- `openspec/config.yaml` — current rules, schema name, and context conventions
- `{GITHUB_REPO}/docs/claude/commands.md` — canonical command descriptions and signatures
- `openspec/schemas/conduction/schema.yaml` — active schema artifact instructions

## What to check for each command and skill file

1. **Broken or stale links** — any `[text](path)` that points to a file, section heading, or anchor that no longer exists or was moved. Cross-check against the actual file tree. Flag every broken path.

2. **Stale content references** — mentions of file paths, command names, persona names, spec names, or tool names that have changed. Example: a command that references an app directory that was renamed, or a skill that lists a command flag that no longer exists.

3. **Duplicated content** — inline content that restates something already clearly covered in a source of truth (per `writing-docs.md` Sources of Truth table). Per the Reference, Don't Duplicate principle: flag blocks of inline guidance that should link to the authoritative doc instead. Common cases:
   - A skill that restates writing-docs.md rules rather than pointing to the section
   - A command that restates spec structure details already in writing-specs.md
   - A command that inlines persona descriptions that live in `personas/`

4. **Missing cross-references** — a command or skill covers a concern for which a relevant doc exists, but doesn't reference it. Example: a testing skill that describes persona behavior without pointing to `personas/`; a spec-creation command that doesn't reference `writing-specs.md`. Only flag where the missing link would meaningfully help Claude.

5. **Writing anti-patterns** — time-sensitive language ("currently", "as of now", "recently"), hardcoded version numbers in prose, vague actors ("the user", "you should"), "see above" / "see below" positional references. See [Writing Anti-Patterns](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#writing-anti-patterns).

   **Before flagging a time-sensitive note as fixable, run the purpose check** ([writing-docs.md → Before removing an anti-pattern, check the note's purpose](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#before-removing-an-anti-pattern-check-the-notes-purpose)):

   - Does the note carry a *reason* (a "why")? If yes — flag for **rewording**, not removal. The reason must survive any edit.
   - Does the note flag a *known mismatch* (name vs behaviour, current state vs intended state, workaround vs upstream fix)? If yes — flag for **softer qualifier** ("as of writing", linked tracking issue), not removal. The mismatch must stay flagged.
   - Does the note acknowledge a *current model behaviour* on which downstream advice depends? If yes — flag for **explicit temporal scoping** ("verify before assuming the guidance still holds"), not removal. Removing the qualifier makes the dependent advice age silently.

   Only flag for **straight removal** when the line is pure dated provenance with no surviving context (e.g. "Observed today (2026-04-19) on #71" can be replaced with the bare issue reference). When in doubt, flag as `[Verify — load-bearing?]` rather than as a confident fix.

6. **Outdated workflow steps** — steps that no longer match current project conventions, tooling, or the command descriptions in `commands.md`. Examples: a skill that says to run a command with a flag that was removed; a command that refers to an artifact phase that no longer exists; a skill that references the wrong browser number for parallel testing.

7. **Content that should be removed** — instructions for features or workflows that have been removed from scope, or caveats that are no longer true. Only remove content when it is clearly no longer valid — if in doubt, flag as `[Verify]`.

8. **Scope creep** — a skill or command that has grown to include guidance that belongs in a different file (e.g. lengthy setup instructions in a testing skill that belong in `getting-started.md`). Flag for extraction and linking.

## Depth guidance

- Read every `SKILL.md` in `.claude/skills/`
- For each file, run all 8 checks above
- Flag only real issues — don't flag something just because it could theoretically be shorter or link somewhere. The bar is: does this mislead Claude, break something, or clearly violate writing-docs.md principles?

## Evals coverage — which skills intentionally have no `evals.json`

Not every skill gets a deterministic evals suite. Skills whose correctness depends on live browser state, a specific persona's perspective, or a human judgement call do not produce reliable pass/fail signal from a static trigger-prompt suite, so they intentionally ship without `evals/evals.json`.

Do **not** flag missing evals for the categories below, and do not add new evals to them without a concrete plan for how the eval can be scored deterministically.

| Category | Skills | Why no evals |
|---|---|---|
| Persona testers | `test-persona-annemarie`, `test-persona-fatima`, `test-persona-henk`, `test-persona-janwillem`, `test-persona-mark`, `test-persona-noor`, `test-persona-priya`, `test-persona-sem` | Scenario-driven — output depends on live app state and persona judgement, not reproducible from a static prompt |
| Test runners | `test-accessibility`, `test-api`, `test-functional`, `test-performance`, `test-regression`, `test-security`, `test-scenario-create`, `test-scenario-edit`, `test-scenario-run` | Execute against a live Nextcloud instance; success criteria live in the scenario, not the skill |
| Scrum-team agents | `team-po`, `team-reviewer`, `team-sm` | Judgement-heavy roles with open-ended output — no canonical "correct" answer per trigger |
| OpenSpec bulk / continuation tooling | `opsx-bulk-archive`, `opsx-continue`, `opsx-sync` | Operate on repo state that varies per invocation; evals would require a reproducible fixture repo |
| App lifecycle | `app-apply`, `app-verify`, `clean-env` | Side-effectful against a running dev environment; pass/fail is observed on the host, not in the skill transcript |
| One-shot repo utility | `verify-global-settings-version` | Single-file check against repo state; pass/fail has no ambiguity worth an eval harness |

Adding evals to a skill in this list requires a plan for how the eval will be scored without depending on live app state, git history, or human judgement — otherwise the suite will produce noise instead of signal.
