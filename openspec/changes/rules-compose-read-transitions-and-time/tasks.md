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

> 🔑 **Built, and built the way the note said it had to be.** `compile()`
> resolves the offset to ONE instant through the working calendar and returns a
> property, an operator and that instant — so the sweep is `WHERE created_at <= ?`
> and not a hundred thousand walks. The arithmetic is the ENGINE'S OWN:
> `workingHours` converts through `SlaCalculator::convert()` and is walked by the
> same `sub()` the timers use, so two screens cannot disagree about one deadline.


- [x] 3.1 `{"$age": {"property": "createdAt", "moreThan": {"value": 3, "unit": "workingHours"}}}`,
      in all four units. Both of the spec's clock scenarios are asserted against
      the SHIPPED `nl-national` calendar: Friday 16:30 → Monday 09:30 holds,
      and the same object on Saturday morning does not.
      🔑 `workingHours` counts HOURS THAT FALL ON WORKING DAYS, which is what
      the engine's business-day walk counts. A window-aware offset — hours
      inside 09:00 to 17:00 — is a different number, `elapsedBusinessHours()`
      measures it and has no inverse, and building one here would be inventing
      arithmetic the arm path does not do. The unit is named for what it
      counts.
- [x] 3.2a Business units resolve through `SlaCalculator` and the calendar,
      never through arithmetic of this class's own.
- [ ] 3.2b Which calendar resolves FOR A SCHEMA is the caller's to decide;
      this takes one and refuses without it. The record-type/unit/instance
      resolution order belongs to `working-calendar-admin` and is not
      re-implemented here.
- [x] 3.3a `compile()` returns `{property, operator, value}` — one instant,
      one comparison. The PHP verdict applies the SAME compiled comparison, and
      a test asserts the two agree across three dates, because a sweep selects
      by query and a save evaluates in PHP.
- [ ] 3.3b Handing it to `MagicRbacHandler`'s query builder in the sweep
      itself. The shape the builder needs is what `compile()` returns; joining
      it in is the sweep's change, not this one.
- [x] 3.4 Refused at save AND at evaluation, by the same check: `compile()`
      runs `refusalFor()` every time, so a condition stored before the
      validator existed meets the refusal at the moment it would otherwise have
      quietly changed meaning. `SlaCalculator::add()` now also refuses a
      business unit with a null calendar, which is a second REACHABLE guard
      rather than a third unreachable one.

## 4. Administered validations

> 🔑 **Built on the evaluation point, not beside it.**
> `AdministeredValidationListener` subscribes to the SAME two events
> `StateFieldRuleListener` does, which is the whole of REQ-RCT-005: every write
> funnels through the two mapper methods that dispatch them, so a path added
> later cannot skip a check an administrator wrote, and
> `RuleEvaluationPointTest` now fails and names it if one tries.


- [x] 4.1 `x-openregister-validations`, added to `Schema::ANNOTATION_VOCABULARY`
      (without which `setConfiguration()` drops it and every violating object
      saves happily — a missing CONTROL, the worst member of that class).
      Refused at save: no condition, an unknown severity, no message, a
      property the schema does not declare. A bare string message is accepted
      as the fallback language, so the simple case is not the awkward one.
- [x] 4.2 Verbatim, with the properties, and with EVERY refusal beside the
      first — a form that can show three problems at once should not make
      somebody save three times to find them.
      🔴 An unevaluable condition refuses whatever its declared severity: a
      check that could not be ASKED has not been passed, and a `warn` that
      quietly becomes "fine" is how one broken named condition switches off a
      mandatory control.
- [x] 4.3a A warning saves, and its message is evaluated and written to the
      rule run log.
- [ ] 4.3b RETURNING it with the response. The save events carry `setErrors()`
      and nothing else — there is no warnings channel on a save response to put
      it in. Recorded rather than dropped while the channel is missing, and
      named here rather than left to look like a feature.
- [x] 4.4 Two new assertions in `RuleEvaluationPointTest`: the listener is
      subscribed to both events, and it records its verdict. The validation is
      also a kind in `RuleVocabulary` (order 4, ahead of flows, which moved to
      5 — a validation refuses BEFORE the object is stored and a flow runs
      after), so the rule inventory lists it like any other rule.

## 5. Tests

- [x] 5.1 20 tests, each refusal with a control beside it. Two mutation
      checks: returning false for an unresolvable reference, and a `$before`
      envelope present-but-empty on a create.
- [x] 5.2 14 tests with explicit instants, including both spec scenarios, the
      wall-clock control that proves the weekend test is not passing on a
      condition that never holds, the compiled threshold, the inverted
      operator, and the unreadable date that refuses rather than reading as
      "not due".
- [x] 5.3 18 tests: verbatim, translated, the regional fallback, the missing
      message refused at save, the undeclared property, the unknown severity,
      the named condition inside a validation, and the unevaluable check that
      refuses.
- [ ] 5.4 The e2e, which needs a surface rendering the message.
- [x] 5.5 Recorded in the PR body: one evaluator (`ConditionDialect`), one
      expression vocabulary, one annotation vocabulary. No second evaluator
      and no second dialect.
