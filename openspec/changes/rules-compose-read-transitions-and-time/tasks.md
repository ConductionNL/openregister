# Tasks: rules-compose-read-transitions-and-time

## 1. Named conditions

- [x] 1.1 Declared under `x-openregister-conditions` on the schema, as
      name → {description, expression}, in the shared vocabulary. Added to
      `Schema::ANNOTATION_VOCABULARY`, without which `setConfiguration()` drops
      it and every rule referencing a name refuses while its author reads a
      200 on the save.
- [x] 1.2a `{"$condition": "name"}` resolves anywhere `NamedConditionEvaluator`
      evaluates, including inside `and`, `or` and `not`. A reference inside a
      shape it cannot compose (`if`) REFUSES rather than guessing.
- [ ] 1.2b The call sites: `LifecycleConditionEvaluator`, `StateConditionEvaluator`
      and the field-rule evaluator each pass `ConditionDialect` directly today.
      Routing them through the named evaluator is a one-line change per site
      plus a library lookup, and it is a separate PR because each site also
      has to decide where its library comes from (schema, register, or both).
- [x] 1.3a `NamedConditionLibrary::refusalFor()` refuses an unknown name
      (naming both the name and what referenced it), a cycle (naming the path
      that closes it, self-reference included) and a chain deeper than
      `MAX_DEPTH`.
- [ ] 1.3b Calling it from the schema save path, which is
      `LifecycleAnnotationValidator`'s neighbourhood and needs the same
      decision about where the library lives.
- [x] 1.4a `usage()` answers condition → the rules naming it. DIRECT
      references only, deliberately: an administrator asking "what does
      correcting this break" wants the rules that name it, and a transitive
      list buries those among conditions that merely compose it.
- [ ] 1.4b Joining it into `RuleInventoryService`'s output.
- [x] 1.5a A refusal, as `ConditionRefusedException` carrying the name and
      the reason. 🔴 It is an exception and not a `false` because a `false`
      fails OPEN one negation later: `{"not": {"$condition": "x"}}` with `x`
      missing would evaluate to TRUE and the rule would fire on everything.
      Both are tested.
- [ ] 1.5b `RuleRunRecorder` writing it, which arrives with 1.2b.

## 2. Before and after

- [x] 2.1 `$before` and `$after` envelopes on the evaluation document.
      The after values ALSO stay at the top level, so every condition written
      before this change keeps meaning what it meant.
- [x] 2.2a Refused, and also refused the other way: a rule that READS the
      prior value without declaring it is refused too, or the declaration is
      decoration and the first check reads a field nobody has to fill in
      truthfully. `$before` is ABSENT on a create, never null, because a null
      would make `$before.status == null` match every create.
- [ ] 2.2b Calling it from the annotation validator, with 1.3b.
- [x] 2.3a `decidedBy()` answers `transition`, `after` or `unchanged` — not
      "which operand was read" but which the verdict turned on, so a run log
      cannot send somebody looking for a move that never happened.
- [ ] 2.3b Writing it to the run log, with 1.5b.

## 3. Relative time

> 🔑 **NOT STARTED, and named rather than half-built.** The pure half (offset
> arithmetic against a clock fixture) would take an hour; the half that matters
> is D-5, "compiled, not interpreted per row" — 'created more than three working
> hours ago' over a hundred thousand objects is a query, not a loop — and that
> needs the working-calendar resolution of `flow-business-timers` and a SQL
> emitter. Building the arithmetic alone would produce a feature that is correct
> on ten objects and unusable on a register, which is the shape of thing that
> gets merged and then quietly never used.


- [ ] 3.1 Relative time. Not started: see the note under section 3.
- [ ] 3.2 Business units resolve through the working calendar the record type resolves.
- [ ] 3.3 The comparison compiles to an indexed query rather than a per-row evaluation.
- [ ] 3.4 An unresolvable calendar is a refusal at save, not a downgrade at evaluation.

## 4. Administered validations

> 🔑 **NOT STARTED.** It needs the save pipeline's evaluation point and the
> write-path enumeration test from `rules-engine-operability` (D-7), plus the
> i18n content path for the message (ADR-025). The condition half it would
> stand on is what this PR builds; section 4 is the next PR on top of it.


- [ ] 4.1 A schema carries validations: a condition, a severity, the properties concerned and a translatable message.
- [ ] 4.2 A refusing validation refuses the save with the administrator's message and the named properties.
- [ ] 4.3 A warning validation returns the message and saves.
- [ ] 4.4 Validations are evaluated in the save pipeline and are covered by the write-path enumeration test.

## 5. Tests

- [x] 5.1 20 tests, each refusal with a control beside it. Two mutation
      checks: returning false for an unresolvable reference, and a `$before`
      envelope present-but-empty on a create.
- [ ] 5.2 Unit tests with a clock fixture for the working-hours comparison.
- [ ] 5.3 Unit tests asserting the administrator's message is returned verbatim in the refusal.
- [ ] 5.4 An e2e over a save refused by an administered validation showing its own message.
- [x] 5.5 Recorded in the PR body: one evaluator (`ConditionDialect`), one
      expression vocabulary, one annotation vocabulary. No second evaluator
      and no second dialect.
