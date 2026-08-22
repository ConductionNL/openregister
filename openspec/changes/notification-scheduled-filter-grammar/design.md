# Design: notification-scheduled-filter-grammar

## Context

See `proposal.md` — *Why*. The short version needed here: the grammar the
evaluator executes and the grammar the validator accepts are two different
grammars, written independently in two files, and the validator's is strictly
larger. Twenty-four filter entries in the fleet fall in the gap.

Three facts constrain every decision below, and all three were verified against
source rather than assumed.

**1. The validator's output is discarded.**
`SchemaMapper::validateNotificationsAnnotation()` builds the errors and then
throws them away:

```php
// lib/Db/SchemaMapper.php:1364-1381
$errors = (new NotificationAnnotationValidator())->validate($shape);
if (count($errors) === 0) {
    return;
}
// … "A malformed OPTIONAL notification annotation must NOT block the schema
//    itself — and, on config import, the entire register + every schema …"
$this->logger->warning('Schema "' . $schema->getSlug() . '" has invalid …');
```

There is no `422` in `lib/Db/SchemaMapper.php`, and no other caller of the
validator exists in `lib/` outside its own file and
`lib/Service/Handoff/HandoffAnnotationValidator.php`'s docblock reference.
`openspec/specs/notificatie-engine/spec.md:846-853` nonetheless says the
validator "MUST reject, with HTTP 422". The spec has been wrong since the
`notification-engine-scheduled-conditions` change, and this design does not
propose to make the code match it — the import-safety reasoning in that comment
is sound. It proposes to make the spec match the code and to put the blocking
enforcement where it can actually block. See Decision 5.

**2. The `created` path has its own filter grammar, and it is not this one.**

```php
// lib/Service/Notification/AnnotationNotificationDispatcher.php:1686-1723
$field    = (string)($filter['field'] ?? '');
if ($field === '') { return false; }
$operator = (string)($filter['operator'] ?? 'equals');
$actual   = ($data[$field] ?? null);
$actualStr = '';
if (is_scalar($actual) === true) { $actualStr = (string)$actual; }
if ($operator === 'in' || $operator === 'notIn') { … }
return $actualStr === (string)($filter['value'] ?? '');
```

Different **shape** (one `{field, operator, value|values}` object, not a map of
entries), different **comparison** (everything cast to string, so `true` and
`"1"` are equal and `1` and `"1"` are equal), and a different **operator set**
(`equals`, `in`, `notIn` — no dates at all). `withinNext`/`olderThan`/`before`
exist nowhere in it.

**3. The scan is fully in-memory and knows it.**
`ScheduledNotificationJob` loads a whole schema's objects and filters in PHP,
bounded by `MAX_OBJECTS_PER_FIRE = 5000` and a rotating offset window
(`lib/BackgroundJob/ScheduledNotificationJob.php:69`, `:329-377`), with the
deferred plan recorded in the constant's docblock:

```php
// lib/BackgroundJob/ScheduledNotificationJob.php:64-67
// TODO(PERF-3): push the trigger `filter` into SQL (a paged findBySchema with
// _filter+_limit in lib/Db/MagicMapper) and add a per-schema watermark for
// delta scans, so we no longer load the whole table into PHP and filter
// in-memory. Until then this cap bounds the blast radius.
```

## ADR-031: declarative vs imperative

ADR-031 makes `x-openregister-*` the default path and requires an imperative
alternative to be justified. This change is a direct application of it, in both
directions.

**The surface stays declarative, and that is the whole point.** A scheduled
notification rule is a statement in a register annotation: *these objects, this
often, this message, these people*. The imperative alternative — each app
writing its own `TimedJob` that queries `MagicMapper` and calls
`AnnotationNotificationDispatcher::dispatchWithSchema()` (public at
`lib/Service/Notification/AnnotationNotificationDispatcher.php:211`) — is
exactly what the census shows people do NOT want to do: 23 rules across three
teams were expressed declaratively, in three dialects, because the declarative
surface was the obvious place to express them. The failure was not that they
chose the declarative path; it is that the declarative path silently accepted
sentences it could not read. Narrowing the grammar to force apps into
imperative jobs would move 23 pieces of scheduling logic into three codebases,
untested and unauditable, and would be a straight ADR-031 violation. The fix is
to make the declarative dialect say what its users are already trying to say.

**The engine internals are imperative, necessarily, and stay so.** The parser,
the AST and the evaluator are PHP in `lib/Service/Notification/`. There is no
declarative way to define a grammar's own semantics; this is the engine, not a
consumer of it. What ADR-031 buys here is the *shape* of the imperative code:
one definition of the grammar with two consumers (Decision 1), rather than the
current two definitions with none in common.

**The gate is where declarative meets mechanical.** Because the surface is
declarative and machine-readable, a checker can read every rule in the fleet
without executing anything (Decision 5). That is a property an imperative
implementation would not have — you cannot grep 23 hand-written `TimedJob`s for
"filters on a field that does not exist".

No lifecycle, aggregation, derived-field, relation or widget annotation is
touched. No schema is introduced or modified, so no Seed Data section applies
(ADR-001).

## Goals / Non-Goals

**Goals**

- One grammar definition; the validator and the evaluator cannot disagree about
  what is executable.
- All 22 currently-dead decidesk and shillinq rules become executable **without
  editing them**.
- The 49 filter entries that work today (28 scalar, 21 canonical-operator)
  behave byte-for-byte identically.
- The normalised filter is an artefact PERF-3 can compile, not an obstacle.

**Non-Goals**

- Editing the 24 dead entries. They are follow-up changes in decidesk, shillinq
  and openconnector; this change makes 22 of them correct as written and tells
  the fourth what to write.
- Unifying the `created` and `scheduled` evaluators. Decision 4.
- Implementing the PERF-3 SQL pushdown. Decision 6 makes it cheaper; it does
  not do it.
- Making a malformed notification annotation fail a schema save or a register
  import. Decision 5.
- Any new notification channel, recipient form, throttle or dedupe behaviour.

## Grammar specification

The complete normative grammar is in
`specs/notificatie-engine/spec.md`. What follows is the implementation-facing
restatement — the exact surface the parser accepts, in one place, so the gate
author and the PHP author are reading the same text.

### Entry forms

A `filter` is a map. Each entry is one of four forms:

| Form | Written as | Means |
|---|---|---|
| Scalar | `{"status": "open"}` | `status === "open"`, strict |
| Bare list | `{"status": ["open","pending"]}` | `in` over the list |
| Operator object | `{"status": {"operator": "in", "values": [...]}}` | the named operator |
| Combinator | `{"all": [clause, …]}` / `{"any": [clause, …]}` | conjunction / disjunction |

Top-level entries are ANDed, unchanged from today. An empty filter matches.
`all` and `any` are reserved top-level keys; the census confirms no fleet
schema filters a field with either name, so reserving them breaks nothing that
exists.

### Clause form (inside a combinator)

`{"field": "<name>", "operator": "<op>", "value": v}` — or `"values": [...]`
for the membership operators. A clause MAY itself be `{"all": [...]}` or
`{"any": [...]}`. Depth is bounded at 5; deeper is a validation error, not a
stack overflow.

This is deliberately the same object shape `createdFilterMatches()` already
reads (`AnnotationNotificationDispatcher.php:1686-1692`). See Decision 4.

### Operators

| Operator | Operand | Semantics | Status |
|---|---|---|---|
| `equals` | `value` | strict `===` | existing |
| `notEquals` | `value` | strict `!==`; missing/null satisfies it for any non-null `value` | existing |
| `withinNext` | `value`: ISO-8601 duration | field date in `(now, now + d]` | existing |
| `olderThan` | `value`: ISO-8601 duration | field date `< now - d` | existing |
| `in` | `values`: non-empty list | strict membership; array field → non-empty intersection | **new** |
| `notIn` | `values`: non-empty list | strict negation of `in`; missing/null satisfies it | **new** |
| `before` | `value`: reference instant | field date `<` instant | **new** |
| `after` | `value`: reference instant | field date `>` instant | **new** |

`in`/`notIn` use the **strict** comparison of the scheduled path, not the
string-coercing comparison of the created path. Decision 4 explains why the two
must not be quietly merged.

### Reference instant (`before` / `after`)

Three spellings, resolved against the scan's single `$now`:

- `"now"` → `$now`.
- An ISO-8601 date or date-time (`"2026-01-01"`, `"2026-01-01T00:00:00Z"`) →
  that instant, absolutely.
- A **signed** ISO-8601 duration → `"P7D"` is `$now + 7 days`; `"-P7D"` is
  `$now - 7 days`. `DateInterval::__construct()` rejects a leading `-`, so the
  parser strips it and sets `invert`.

The sign is mandatory for the past direction and there is no unsigned "past"
spelling. This is the one place the grammar could have been ambiguous —
`{"dueDate": {"operator": "before", "value": "P7D"}}` reads equally naturally as
"before a week from now" and "before a week ago" — and an ambiguous date
operator in a reminder engine is how you send a thousand wrong emails. Making
the sign explicit removes the reading entirely.

Anything else fails closed: the entry does not match, and nothing matches, which
for a `before "soon"` typo means silence rather than a mass dispatch.

### Compatibility of the three broken dialects

| Dialect | App | Rules | Entries | Example | After this change | Edit needed |
|---|---|---|---|---|---|---|
| Bare list = membership | decidesk | 18 | 18 | `{"lifecycle": ["open","in-uitvoering"]}` (`45-toezeggingen-ingekomen-stukken.json:216`) | **Executable.** Parsed as `in` over the list. | **None** |
| `all` + `notIn`/`before`/`equals` | shillinq | 4 | 4 (7 clauses) | `{"all":[{"field":"state","operator":"notIn","values":["paid","written-off","voided"]},{"field":"dueDate","operator":"before","value":"now"}]}` (`bookkeeping-accounts-payable-core.json:840`) | **Executable.** `all` is a combinator; `notIn` and `before "now"` are in the grammar. | **None** |
| `op` key + `lt` | openconnector | 1 | 2 | `{"isEnabled":{"op":"equals","value":true},"nextRun":{"op":"lt","value":"now"}}` (`openconnector_register.json:1154`) | **Still not executable** — and now a validation ERROR naming `operator`, instead of a silent accept. | **Yes**: `op`→`operator`, `lt`→`before` |
| Canonical operator object | fleet-wide | — | 21 | `{"dueDate":{"operator":"withinNext","value":"PT24H"}}` | Unchanged, byte-for-byte. | None |
| Scalar | fleet-wide | — | 28 | `{"lifecycleState": "overdue"}` (`shillinq-notifications.json:17`) | Unchanged, byte-for-byte. | None |

22 of the 23 dead rules come back to life on deploy, with no edit in any app.
That outcome is the reason bare-list and `all` were chosen over cleaner
alternatives (Decision 2).

The shillinq rules are worth one extra check, because the sibling rewrite note
at `shillinq-notifications.json:7` says the ARInvoice version of this filter
was *also* wrong about its field name (`state` does not exist on `ARInvoice`;
the field is `lifecycleState`). The APTransaction rule is not affected by that:
`bookkeeping-accounts-payable-core.json` does declare `APTransaction.state` as
an enum containing `paid`, `written-off` and `voided`. So the four shillinq
rules are dead **only** because of the grammar, and fixing the grammar is
sufficient for them.

## Decisions

### Decision 1 — One parser, two consumers (the root-cause fix)

Introduce `lib/Service/Notification/ScheduledFilterParser.php`, which turns a
raw `filter` array into either a normalised AST or a list of structured errors.
`ScheduledFilterEvaluator` evaluates the AST.
`NotificationAnnotationValidator::validateScheduledFilterEntry()`
(`:1018-1102`) reports the parser's errors.

Alternatives considered:

- *Just add the operators to both files.* This is what the last change did:
  `validateScheduledFilterEntry()` and `entryMatches()` were written to the same
  four operators independently, and they still drifted — the validator's
  accept-set is `{scalars} ∪ {arrays without an "operator" key} ∪ {four valid
  operator objects}` while the evaluator's execute-set is `{scalars} ∪ {four
  valid operator objects}`. Adding four more operators to two hand-kept lists
  doubles the surface on which the same drift can recur.
- *Generate one from the other.* No mechanism in this codebase does that, and
  inventing one is more machinery than the problem needs.

The AST also gives a place to put the depth bound and the reserved-key handling
once, rather than in both files.

### Decision 2 — Adopt the invented dialects rather than a cleaner one

`in`, `notIn`, `before`, `after`, bare-list and `all`/`any` are not chosen on
aesthetics. They are chosen because three teams, independently and without
coordination, reached for exactly these forms, and because adopting them makes
22 of the 23 dead rules correct with **zero edits in three other repositories**.

The alternative — define a cleaner grammar (say, `{"operator": "oneOf",
"values": [...]}` and an explicit `{"operator": "and", "clauses": [...]}`) —
would require 22 follow-up edits, each a PR in another repo, each a chance to
get it wrong again, in exchange for a marginally tidier keyword. The evidence
that these forms are the natural ones is the defect itself.

`op` is the one invented spelling **not** adopted. Two rules use it against
21 that use `operator`; accepting both would create a second permanent spelling
for the sake of one rule, and permanent synonyms in a dialect are how dialects
fragment. Instead the validator names `operator` in the error message so the fix
is obvious rather than guessable.

### Decision 3 — Bare list means `in`, and this is a knowing (empty) break

Today a list spec reaches `$actual === $spec` (`ScheduledFilterEvaluator.php:118`)
and therefore means *"the field holds exactly this array"* — meaningful for a
multi-select field. After this change it means membership. That is a semantic
break in the letter of the contract.

Measured blast radius: zero. The census classifies every scheduled filter entry
in every `lib/Settings/**/*.json` in the workspace: 28 scalar, 21 canonical
operator objects, 18 bare lists, 4 combinators, 2 `op`-key. All 18 bare lists
are on scalar enum fields (`lifecycle`, `status`) in decidesk, every one of them
plainly intended as membership, and none of them can match today. No rule
anywhere relies on array-identity.

The array-valued-field case does not disappear, it gets a defined answer:
when the object's field is itself a list, `in` matches on non-empty
intersection. That is the useful reading for a multi-select field and it is
specified rather than emergent.

### Decision 4 — Converge the vocabulary, not the comparison; do not merge the two evaluators

The `created` and `scheduled` filters diverging is itself a defect risk — the
brief is right about that, and the openconnector census result proves it:
`call_log.call-failed` (`openconnector_register.json:1940`) and
`job_log.job-error` (`:2114`) are `created` rules that pass a **map-shaped**
filter to a function that reads `$filter['field']`, so they return false at
`:1687-1690` and have never fired either. Same author, same misconception, other
path.

So this change converges what can be converged safely:

- **The clause shape.** A scheduled combinator clause is
  `{field, operator, value|values}` — deliberately the created path's object,
  so a developer who has written one has written the other.
- **The operator names.** `in`/`notIn` mean the same thing in both, and now
  exist in both.
- **The detection.** The gate (Decision 5) checks both trigger paths, so the two
  dead `created` rules are caught by the same net.

And it explicitly does **not** converge the comparison semantics.
`createdFilterMatches()` casts both sides to string (`:1694-1697`, `:1723`), so
`{"statusCode": {"operator":"equals","value":400}}` matches the integer `400`
and the string `"400"` alike, and `{"isEnabled": true}` matches `1`, `"1"` and
`true`. The scheduled path is strict `===`. Making the created path strict
would silently stop matching wherever a JSON number meets a string column — a
regression with no failing test to announce it, in rules that fire on writes.
Making the scheduled path coercive would change the meaning of the 28 scalar
entries that work today, which the Goals forbid.

Merging the two evaluators therefore means picking one comparison and breaking
the other path's live rules. The correct sequencing is: fix the grammar and the
detection now, then converge the comparison as its own change with its own
before/after census of every `created` rule in the fleet. This design records
that as required follow-up rather than pretending the divergence is fine.

### Decision 5 — Detection belongs in the Hydra gate, with unit tests as the engine's own proof

Both, with a clear division of labour, and the gate is the part that blocks.

**OpenRegister unit tests** (`tests/Unit/Service/Notification/ScheduledFilterEvaluatorTest.php`,
`NotificationAnnotationValidatorTest.php`, plus a new parser test) prove the
engine: every operator arm, every fail-closed path, every rejection. They cannot
do the job on their own for one structural reason — the 23 dead rules live in
`decidesk/lib/Settings/register.d/*.json`,
`shillinq/lib/Settings/register.d/*.json` and
`openconnector/lib/Settings/openconnector_register.json`. OpenRegister's test
suite never reads those files and never will; they are other repositories.

**Hydra gate-18** is where the fleet-wide check goes. Three reasons, in order of
weight:

1. **It is the only place that can block a merge.** OpenRegister's validator
   cannot: `SchemaMapper.php:1364-1381` logs its findings and returns. Even a
   perfectly strict validator would have let all 24 entries install. The gate
   fails a PR.
2. **It already reads exactly these files.**
   `.github/hydra-gates/scripts/lib/check_notification_dialect.py` JSON-parses
   `lib/Settings/*register*.json` and `register.d/*.json`, iterates every
   `x-openregister-notifications` block and scans each rule
   (`_iter_notification_blocks`, `_scan_rule`), and is wired at
   `.github/hydra-gates/scripts/run-hydra-gates.sh:3926-4031` with a
   crashed-checker guard and an empty-scope guard already in place. The new
   check is a function in a file that already has the data in hand.
3. **It runs in the repo where the rule is authored,** at the moment it is
   authored, which is the only moment the author is available to fix it.

The gate's check (c): for every `scheduled` trigger filter entry, classify as
executable or not; report `<file>: rule=<ruleKey> field=<field> reason=<why>`;
non-executable is a **hard fail**, matching check (a)'s posture rather than
check (b)'s advisory warning. It also classifies `created` trigger filters
against `createdFilterMatches()`'s shape, which catches openconnector's other
two dead rules.

Alternatives considered:

- *An `occ` command operators run after import.* Detects, but after the fact and
  only if someone runs it. The 24 entries survived months of imports.
- *Make the schema save fail.* Rejected on the same grounds the existing comment
  gives: on config import one malformed optional annotation would abort the
  whole register and every schema referencing it, and zero objects would land.
  Turning a silent dead rule into a total import failure is a worse trade.
- *An OpenRegister test that globs `../*/lib/Settings/`.* Depends on a workspace
  layout that exists on one developer's machine and in no CI job.

Drift between the PHP grammar and the gate's Python is the obvious hazard and is
handled by naming a single source of truth: the operator table in
`openspec/specs/notificatie-engine/spec.md`, which the spec now states any
external checker must derive from. Task 5.3 pins the gate's copy against a
fixture set generated from the PHP constant.

### Decision 6 — The AST is what makes PERF-3 cheaper, not harder

A richer grammar sounds like it should make SQL pushdown harder. It does the
opposite here, for a specific reason: every operator in the table above is a
**single-column predicate**, and the two combinators are **conjunction and
disjunction**. That is a `WHERE` tree.

| Grammar node | SQL |
|---|---|
| `equals` / `notEquals` | `col = ?` / `col <> ?` |
| `in` / `notIn` | `col IN (…)` / `col NOT IN (…)` |
| `before` / `after` | `col < ?` / `col > ?` (instant resolved in PHP against the scan's `now`) |
| `withinNext` / `olderThan` | `col > ? AND col <= ?` / `col < ?` |
| `all` / `any` | `AND` / `OR` |

What blocks pushdown today is not expressiveness, it is that the filter is
consumed as raw JSON inside `entryMatches()` — there is nothing a query builder
can be handed. The AST is that thing. `MagicMapper`'s `_filter` is the target
(`ScheduledNotificationJob.php:64-67`), and a compiler from AST to `_filter` is a
strictly separate change with a clean input.

Two constraints this design imposes now so PERF-3 does not have to relitigate
them:

- **The `now` is resolved before compilation.** `withinNext`, `olderThan`,
  `before` and `after` all resolve to absolute instants against the scan's
  single `$now`, so a compiled predicate is a plain comparison with a bound
  parameter and the scan stays consistent across a paged sweep.
- **Partial pushdown must agree with full in-memory evaluation.** The spec
  requires it, and the AST makes it checkable: push what compiles, evaluate the
  rest in memory over the reduced set, and the result is identical because
  conjunction is associative and the leaves are pure.

The `TODO(PERF-3)` comment and the rotating-window warning at `:347` are updated
to say the AST is the input, so the next person to open that file finds the plan
where the plan already is.

## Risks / Trade-offs

- **Bare-list re-meaning silently changes an array-identity filter** → Census
  shows zero such filters exist (Decision 3). The gate's check (c) reports the
  bare-list form so a future array-identity author is told at PR time that the
  form means membership.
- **22 rules go from dispatching nothing to dispatching** → This is the point,
  but the first scan after deploy will find a backlog: every decidesk
  `Toezegging` past its deadline, every overdue shillinq obligation, all at
  once. Mitigated by the existing per-object dedupe
  (`NotificationDedupeStateMapper`, spec requirement "Scheduled rules MUST
  deduplicate dispatch per object") which caps it at one notification per object
  per fingerprint rather than one per scan — but it is still a burst, and it
  lands in three apps that have never seen these notifications. Task 6.2 stages
  the rollout: land the grammar, then let each app enable its rules
  deliberately.
- **`all`/`any` reserved as top-level keys** → Zero fleet collisions today
  (verified); a schema that later adds a field named `all` filters it via a
  clause. The validator names the reserved key in its error rather than
  producing a confusing type complaint.
- **PHP and Python grammars drift again** → Decision 5's single source of truth
  plus the fixture-pinning task. This is a real ongoing cost and worth naming as
  one: two implementations of one grammar is a compromise forced by the gate
  runner being Python and the engine being PHP.
- **`created` and `scheduled` still compare differently** → Recorded, scoped and
  deferred with reasons (Decision 4), not left implicit. The gate covers both
  paths so the *shape* class of defect is closed on both even while the
  comparison semantics diverge.
- **The spec loses its "HTTP 422" language** → Deliberate. It described
  behaviour that has never existed; leaving it in leaves a second, quieter
  spec/code divergence next to the one being fixed.

## Migration Plan

No database migration, no schema change, no configuration change.

1. Land the parser, evaluator and validator together. They are one grammar; a
   deploy with only one of them re-opens the drift.
2. Deploy OpenRegister. On the next `ScheduledNotificationJob` tick the 22
   decidesk/shillinq rules begin evaluating. Nothing in those apps changes.
3. Land the gate-18 extension in `ConductionNL/.github`. From that point a new
   dead filter fails its own PR.
4. Follow-ups in the consuming repos: openconnector fixes `job.job-overdue`
   (`op`→`operator`, `lt`→`before`) and its two `created` rules; decidesk and
   shillinq need nothing.

**Rollback.** Revert the OpenRegister commit. The grammar is additive to the
evaluator (new `case` arms and two new entry forms), so reverting returns the
four-operator behaviour and the 24 entries return to being inert — the state
they have been in all along. No data is written by this change, so there is
nothing to unwind. The one asymmetry worth stating: rules that fired between
deploy and rollback stay fired, and their dedupe rows stay written, so a
re-deploy will not re-notify for an unchanged object.

## Open Questions

- Should the gate read the operator list from a small machine-readable contract
  shipped by OpenRegister (e.g. under `.github/hydra-gates/contracts/`) instead
  of holding its own pinned copy? Provisionally: pinned copy plus a fixture
  drift test, because the gate must run against a repo checkout that may not
  contain OpenRegister at all. Deferrable — it changes neither the grammar nor
  the tasks.
- Is a nesting bound of 5 right? Provisionally yes; no fleet filter nests at
  all, and the number only needs to be finite and stated.
