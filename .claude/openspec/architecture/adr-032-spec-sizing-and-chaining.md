# ADR-032: Spec sizing taxonomy and chained-spec routing

## Status
Proposed

## Date
2026-05-07

## Context

Hydra was built around the idea of "one OpenSpec change → one Hydra
cycle → one PR". That model works perfectly for the kind of work
ADR-024 (manifest) and ADR-031 (declarative business logic) optimised
the fleet for: small declarative JSON edits — add a route to
`src/manifest.json`, declare an aggregation on a schema, add a
calculation, register a notification.

It does *not* work for code refactors. The 2026-05-07 Stage A
empirical run on decidesk surfaced this concretely:

- Two ADR-031 migration specs were drafted (`quorum-declarative-migration`,
  `actionitem-analytics-declarative-migration`).
- Each was a "config + code" envelope: schema-register patches PLUS
  controller updates PLUS service-class deletions PLUS test rewrites
  PLUS frontend wire-shape verification.
- Both pipelines burned the full 200-turn Sonnet builder budget without
  producing a PR. The orchestrator detected "Claude session closed
  without running gh pr create" and emitted `build:fail`.
- Real builder work happened — test files were edited, phpunit ran
  (1 failure, 30 skipped), schema register patches were drafted —
  but the scope was too big for one cycle.

Reading the post-mortem, the failure mode is structural:

1. **Hydra's reviewer pipeline is judgment-heavy on code surfaces** —
   18 mechanical gates check PHP authoring discipline (SPDX, route auth,
   IDOR, semantic auth, etc.). Each is fast but they compound for
   multi-file refactors.
2. **The schema engine's reviewer surface is much smaller** — for a
   register patch, the relevant checks are: schema validation, ADR-031
   declarative-fit confirmation, integration test coverage. ~3 gates,
   cheap.
3. **A "mixed" spec exercises both surfaces in one cycle** and competes
   for the same 200-turn budget that was sized for one or the other.

The fix is a taxonomy + chaining discipline, not a budget bump.
Bumping max_turns just delays the same cliff.

## Decision

### Every change has a `kind`

Three kinds, declared in the proposal frontmatter:

| `kind:` | What it touches | Hydra route | Default budget | Reviewer scope |
|---|---|---|---|---|
| `config` | Only declarative JSON (`lib/Settings/{app}_register.json`, `src/manifest.json`, OpenAPI specs, schema files, register templates). Integration tests for the new config are allowed. | Hydra builder, default | 200 turns Sonnet | Schema/manifest validation + ADR-031 declarative-fit check + integration test coverage |
| `code` | PHP / Vue / TS / etc. May incidentally touch declarative JSON, but the centre of mass is code. | Hydra builder OR manual (decided per spec) | 200 turns (small) or 400 via `HYDRA_BUILDER_MAX_TURNS` (larger) | Full code review (all 18 gates) |
| `mixed` | Both declarative JSON edits AND non-trivial code edits in one envelope. | **Reject — split first** | n/a | n/a |

`mixed` is an anti-pattern. The two Stage A specs were `mixed` in
hindsight; that's why they failed. Anti-pattern detail in
"Enforcement" below.

### Multi-step migrations chain via `depends_on`

Hydra already supports per-spec `depends_on` in `hydra.json` (see
hydra/CLAUDE.md → hydra.json Schema → `depends_on` field; the
supervisor blocks a spec from building until each named dep is
closed). This ADR makes chaining the **default** pattern for any
migration that touches both declarative and code surfaces:

1. **Spec 1 (`kind: config`)** — declare the new schema metadata. Add
   the integration test that verifies the materialised values are
   correct. Engine-dependency spike (when applicable) lives here.
   Merges first; the new fields are now read-only-available on every
   object.
2. **Spec 2+ (`kind: code`)** — update consumers (controllers, guards,
   widgets) to read the new declarative fields. Each `depends_on`s the
   schema spec.
3. **Spec N (`kind: code`)** — delete the obsolete imperative
   implementation (the service class + its tests + DI wiring). May be
   bundled with spec 2 if small; spun out if not.

The chain is **explicit in the proposal**: each spec's frontmatter
lists its predecessors, and the proposal narrative names the chain
(e.g. "this is spec 2 of 3 in the quorum-migration chain; spec 1 is
`quorum-schema-declaration`, spec 3 is `quorum-service-deletion`").

### Why chaining works

Three properties chain-splitting buys that one big spec doesn't:

- **Engine dependencies surface early.** Spec 1 spikes the cross-schema
  aggregation in isolation. If the engine can't express it, the chain
  pauses on spec 1 — code work in spec 2+ is never wasted.
- **Reviewer scope per spec is tight.** A `config` spec runs through
  3-4 schema-relevant checks; a `code` spec runs through 18. Mixing
  them is what blew the budget — 18 + 4 + multi-file-orchestration is
  how Sonnet hits 200 turns.
- **Mid-chain merge is safe.** The schema declarations land first (no
  consumer change). Existing consumers ignore the new fields. New
  consumers can opt-in incrementally. This pattern is the same shape
  as Postgres expand-then-contract migrations.

### How to declare

In **proposal.md frontmatter**:

```yaml
---
kind: config
depends_on: []
chain:
  - quorum-schema-declaration   # this spec
  - quorum-guard-rewrite         # next in chain
  - quorum-service-deletion      # last in chain
---
```

In **hydra.json** (created/updated by orchestrator):

```jsonc
{
  "schema_version": 2,
  "spec_slug": "quorum-guard-rewrite",
  "kind": "code",
  "depends_on": ["quorum-schema-declaration"],
  ...
}
```

`depends_on` references **issue numbers** (or issue URLs) once the
chain has been planned-to-issues. Until issues exist, reference by
spec slug; the planner translates slug → issue at issue-creation time.

### When to NOT chain

Two cases where a single spec is correct:

1. **Pure config, no code changes downstream.** If declaring the
   metadata is the entire change (e.g. add a notification trigger,
   add an aggregation a dashboard already polls), no chain needed.
2. **Pure code, no declarative surface.** External integration glue
   (CalDAV, ZGW, ORI), document generation, NLP — already-imperative
   work that has no declarative analogue per ADR-031.

The chain pattern applies specifically to **migrations from imperative
to declarative**, where the natural shape is "declare → consume →
delete imperative".

### Thin-glue exception (mixed but small)

A `mixed` spec is permitted when the code change is genuinely thin
glue (≤20 LOC across ≤2 files) and is tightly coupled to the config
change. Document the coupling in design.md under
"Mixed-spec rationale". Still a yellow flag in review; the reviewer
asks "could this glue have been deferred to a chain spec 2?". Most of
the time the answer is yes, and the reviewer suggests the split.

### Enforcement

- **`opsx-ff`**: at proposal generation, asks the spec author "is this
  config-only, code-only, or both?" and emits `kind:` in the frontmatter.
  If `kind: mixed`, opsx-ff offers to split the proposal into a chain
  before any other artifact is written. Provisional default `kind` is
  inferred from the change description; the user confirms.
- **Builder pre-flight (`gate-32-spec-kind`, future)**: parses the
  spec's `tasks.md` for file extensions touched. If config + code
  mixed without a documented thin-glue exception → soft-fail (warn
  the builder; record `pattern_tags: ["spec-kind-mixed"]` on the
  cycle). Mechanical detection by file-extension classification:
  `*.json` (declarative) vs `*.php`/`*.vue`/`*.ts`/`*.js` (code).
- **Reviewer**: same parser; surface as WARNING. The reviewer's
  bounded-fix authority does NOT extend to splitting a spec —
  splitting is the spec author's job. The reviewer flags + escalates.
- **Hardening timeline**: gate-32 stays as a soft warning for 30 days
  after this ADR lands. Post-30-days, if the false-positive rate is
  acceptable (target: <10% on observed PRs), promote to BLOCKING.
  Track as a hydra issue.

### `kind: code` budget rules

For `kind: code` specs that genuinely need >200 turns, two paths:

1. **Split further.** Most "I need 400 turns" code specs are actually
   2-3 specs in disguise. Apply the chain pattern.
2. **Explicitly bump.** When splitting isn't viable (e.g. atomic
   refactor that genuinely can't decompose), set
   `HYDRA_BUILDER_MAX_TURNS=400` in the issue body or as a label
   `budget:large`. The supervisor reads this and dispatches with the
   bumped budget. Soft-cap remains at 800; beyond that the spec
   should be manual.

## Consequences

### Positive

- **Hydra builds finish.** Config specs at 30-80 turns each; chains
  for full migrations. Stage A's failure mode (200-turn timeout) is
  retired.
- **Engine dependencies surface early and isolated.** The
  cross-schema-aggregation spike that gated quorum + analytics now
  lives in a single ~30-turn spec. If it fails, the chain pauses
  cleanly.
- **Reviewer scope is right-sized per spec.** Config specs check
  what's relevant to schemas; code specs check what's relevant to
  code. No 18-gate review on a register-patch PR.
- **Spec authors think in atomic units.** Forces the
  expand-then-contract migration pattern by default — same hygiene
  Postgres / Liquibase / DB-migration teams have used for decades.
- **`opsx-ff` becomes a spec-shape coach.** "What you described is
  mixed; here's the chain I'd suggest" is a useful dialogue, not a
  blocker.

### Negative

- **More specs per logical change.** A 3-spec chain is more
  orchestration than 1 spec. Mitigated by chains being short (3-5
  specs typical) and the orchestrator handling `depends_on` blocking
  automatically.
- **Half-migrated states surface in code.** During chain execution,
  some consumers read the new declarative field while the imperative
  implementation still exists. Mitigated by the expand-contract
  pattern — both work simultaneously, switch happens atomically at
  the consumer level.
- **`opsx-ff` UX gets one more question.** The kind classification
  question adds 30s to spec generation. Acceptable in exchange for
  catching `mixed` early.
- **Spec library noise increases short-term.** Migration of N services
  → 3N specs. Mitigated by archive discipline (per `opsx-archive`)
  once a chain is fully merged.

### Migration

Existing in-flight specs that are `mixed` (per the 2026-05-07 audit:
PRs #146, #147 on decidesk):

1. Re-classify as `mixed`, mark the existing PR closed-as-superseded.
2. Split into a chain. Re-name the original change as the schema-only
   member; create new chain members for code.
3. Issues #148, #149 get closed with a comment naming the new chain
   member issues; ready-to-build labels move to the new schema-only
   issues.

Concretely for the two Stage A specs:

- **`quorum-declarative-migration`** → chain:
  - `quorum-schema-declaration` (config) — declare aggregations + calculations + integration test + engine spike
  - `quorum-guard-rewrite` (code, small) — `MeetingTransitionGuard` reads the new fields
  - `quorum-service-deletion` (code, small) — delete `QuorumService.php` + DI + tests
- **`actionitem-analytics-declarative-migration`** → chain:
  - `analytics-schema-declaration` (config) — declare aggregations on Meeting for actionItem completion + integration test
  - `analytics-controller-rewrite` (code, small) — `AnalyticsController` reads new fields
  - `analytics-getCompletionRates-deletion` (code, small) — delete the obsolete service method

Same shape will apply to the other three planned ADR-031 migrations
(VotingService, MotionService, DecisionNotificationService). Each
becomes a 2-3 spec chain.

## See also

- **ADR-013** — container model strategy. The 200-turn Sonnet budget
  this ADR works within is the same budget; we right-size the *scope*,
  not the budget.
- **ADR-020** — gate scope is the PR diff. Per-spec scope discipline
  inherits naturally — a config-only spec PR has no code-relevant
  gate failures because no code changed.
- **ADR-021** — reviewer bounded-fix scope by shape. A config spec's
  bounded-fix shape is "edit one JSON block". Tight.
- **ADR-024** — app manifest. The frontend declarative principle that
  this ADR's `kind: config` extends to backend schema metadata.
- **ADR-031** — schema-declarative business logic. The migration class
  that this ADR's chain pattern is purpose-built for.
- **hydra/CLAUDE.md → hydra.json Schema → `depends_on`**. The
  underlying mechanism this ADR formalises as the chain primitive.

## Alternatives considered

1. **Bump default builder budget to 400 turns.** Rejected. Stage A
   showed that "200 wasn't enough"; 400 wouldn't cover the same
   refactors with a comfortable margin and lets the same anti-pattern
   compound. The right intervention is scope, not budget.
2. **Split mixed specs only after the first failure.** Rejected.
   Already what Stage A did empirically; observation: half the team
   doesn't know what "right-sized" looks like, so the split-after
   pattern produces a flurry of `rebuild:queued` cycles instead of
   one clean chain. Codifying the taxonomy upstream prevents the
   first failure.
3. **Make Hydra exclusively config-only; route all code work to
   manual.** Rejected. Many `code` specs are clean small diffs
   Hydra handles fluently (e.g. "delete a method", "add a guard
   clause"). The cliff is at "mixed", not at "code".
