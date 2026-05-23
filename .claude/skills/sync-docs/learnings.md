# Learnings — sync-docs

## Patterns That Work

- 2026-04-23 — Delegating Phase 6 Part B (60+ skill SKILL.md audits) and Part C (doc-structure review) to parallel general-purpose agents kept the main conversation's context lean and cut wall-clock by ~5 min vs sequential. The agents returned a single-table report per run, which is trivial to consolidate.
- 2026-04-23 — Pre-flight Check B caught a stale Sources of Truth entry (`hydra/openspec/architecture/README.md`) that referenced a file that never existed. A plain `ls` on every path in the Sources of Truth table was enough — worth doing every run.

## Mistakes to Avoid

- 2026-04-30 — **Never add inline change markers like `# New` or `<!-- New -->` to edited lines.** These comments clutter the file, don't belong in source-controlled docs, and are invisible to readers without git diff context. Let the git commit message carry the "what changed" signal instead.


- 2026-04-23 — Do NOT blindly replace `.claude/personas/` → `hydra/personas/` across skills via grep+replace_all. `.claude/personas/` is a real directory in sibling workspaces (e.g. wordpress-docker/). The path works in some invocation contexts and breaks in others. This is a repo-wide convention decision, not a sync fix — flag it for human review.
- 2026-04-23 — Same for `.claude/docs/writing-specs.md`. `.claude/docs/` exists in sibling workspaces but not in hydra. The skill's `.md`-only edit guardrail is load-bearing: it prevents a naive canonicalization here.
- 2026-04-23 — **Do NOT remove forward-looking / time-sensitive notes without a purpose check.** First Part B run stripped `hydra-gate-spdx`'s "The naming will migrate once all legacy tooling is updated" because it pattern-matched "will" + future tense, but that line was the *only* place explaining why the gate name (`spdx-headers`) doesn't match what the gate enforces (PHPDoc `@-tags`). Same near-miss on `skill-creator`'s "Note: currently Claude has a tendency to undertrigger skills" — removing "currently" silently converted an observation into a permanent claim. Run the three-question purpose check from [writing-docs.md → Before removing an anti-pattern](https://github.com/ConductionNL/.github/blob/main/docs/claude/writing-docs.md#before-removing-an-anti-pattern-check-the-notes-purpose) on every flagged anti-pattern. If a note explains a *why*, flags a *known mismatch*, or scopes downstream *advice*, the fix is rewording or softer qualification — not deletion.

## Domain Knowledge

- 2026-04-23 — Hydra's ADR numbering has a gap: files on disk are ADR-001–015, 017, 018. ADR-016 does NOT exist. Any doc or skill that says "ADR-001 through ADR-016" or "Seed data (ADR-016)" is stale. Seed data is governed by ADR-001 (data layer), not ADR-013 (container pool) and not ADR-016.
- 2026-04-23 — The conduction schema (`openspec/schemas/conduction/schema.yaml`) hardcodes `.claude/docs/writing-specs.md` at lines 90 and 178 as the canonical writing-specs location. The actual canonical path per writing-docs.md Sources of Truth is `.github/docs/claude/writing-specs.md`. The skill cannot auto-fix this (YAML is out of scope); requires a separate edit.
- 2026-04-23 — The `sync-docs` SKILL.md had an ADR audit-checklist that shuffled four ADR numbers to the wrong concerns (was: 003=NL Design, 005=i18n, 007=Security, 010=Docs; corrected to: 010=NL Design, 007=i18n, 005=Security, 009=Docs). Every App docs sync run prior to this correction would have misattributed ADR compliance findings.

## Open Questions

- ~~2026-04-23 — Should hydra's skills use `personas/` (hydra-relative), `hydra/personas/` (apps-extra-relative, per writing-docs.md SoT), or `.claude/personas/` (workspace-convention)?~~ **Resolved 2026-04-23:** hydra skills are invoked from apps-extra workspace CWD, so persona references use `hydra/personas/<slug>.md` (matches writing-docs.md Sources of Truth); `.github/` dev docs use full GitHub URLs since `.github` is not a subdir of the workspace. Documented in `.github/docs/claude/writing-skills.md` → "Path Conventions in Skill Content". Migration applied: 12 persona-touching hydra skills + 1 test-counsel sub-agent template (15 occurrences total) moved from `.claude/personas/` → `hydra/personas/`, and 3 opsx-* skills moved from `.claude/docs/writing-specs.md` → GitHub URL. Workspaces that are `.claude`-centered (e.g. wordpress-docker) retain their own `.claude/personas/` convention — the exception clause in writing-skills.md covers this.
- 2026-04-23 — Upstream `spec-driven` schema has a "Common pitfall: Using MODIFIED with partial content loses detail at archive time" gotcha that the conduction fork is missing. Should it be merged, given the fork intentionally customizes most of the specs instruction? Worth a decision when next touching schema.yaml.
- 2026-04-23 — Eval run (see `evals/grading.json`, `md-only-guardrail-schema-yaml` 0.78, `dev-docs-branch-divergence` 0.92) surfaced two related gaps: (1) Step 3 branch-divergence uses soft "offer to `git pull`" wording instead of an explicit blocking AskUserQuestion with three options (pull / acknowledge-and-continue / cancel) — implementations can interpret "offer" as warning-only. (2) Preflight Check C detects YAML drift between writing-specs.md and schema.yaml but there's no Guardrail requirement to surface the drift in the Phase 5 summary as `[Manual Follow-Up Required]`. Both are the same pattern as the sibling wordpress-docker `md-only-guardrail` eval (0.75): Guardrails are strong *negative constraints* ("never") but weak on *communication protocols* ("when X happens, do Y").

## Consolidated Principles

- **`.md`-only edit guardrail is load-bearing.** It's not just a guardrail against surprising the user — it prevents the skill from making risky canonicalizations (paths, code references) that require broader project decisions.
- **Pre-flight Check B (Sources of Truth existence) is the cheapest win.** It is pure file-exists assertions against a small table; it catches the highest-signal staleness (dangling canonical paths) with the lowest risk.
- **Trust-but-verify agent output for path claims.** Part B agents will flag `.claude/personas/` as wrong when comparing against writing-docs.md — but the path may be right in some contexts. Always verify path existence in the invocation context before fixing.
