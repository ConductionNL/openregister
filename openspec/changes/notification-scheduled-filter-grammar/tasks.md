# Tasks: notification-scheduled-filter-grammar

## 1. The grammar, defined once

- [ ] 1.1 `lib/Service/Notification/ScheduledFilterGrammar.php` — the operator
      table as constants: `OPERATORS` (`equals`, `notEquals`, `withinNext`,
      `olderThan`, `in`, `notIn`, `before`, `after`), `MEMBERSHIP_OPERATORS`,
      `DURATION_OPERATORS`, `INSTANT_OPERATORS`, `COMBINATORS` (`all`, `any`),
      `MAX_DEPTH = 5`. `@license EUPL-1.2`, `@copyright 2026 Conduction B.V.`
- [ ] 1.2 `lib/Service/Notification/ScheduledFilterParser.php` — raw `filter`
      array → normalised AST (leaf nodes `{field, operator, operand}` under
      `all`/`any` nodes) OR a list of structured errors in the existing
      `{code, ruleKey, field, value, message}` shape. Accepts the four entry
      forms; reserves `all`/`any` at the top level; enforces `MAX_DEPTH`.
      Resolves nothing time-dependent — the AST holds the raw operand.
- [ ] 1.3 Reference-instant resolution in the parser's operand validation and a
      `resolveInstant()` helper on the evaluator: `"now"`, an ISO-8601
      date/date-time, or a **signed** ISO-8601 duration (`P7D` = `now + 7d`,
      `-P7D` = `now - 7d`; strip the `-`, set `DateInterval::$invert`).
      Unresolvable → parser error at save time, `null` → no match at scan time.

## 2. Evaluator

- [ ] 2.1 `ScheduledFilterEvaluator::matches()` parses once via
      `ScheduledFilterParser` and walks the AST; `entryMatches()`
      (`ScheduledFilterEvaluator.php:113-170`) is replaced by an AST walker.
      A filter that fails to parse matches nothing and logs at warning level —
      unlike a bad date, an unexecutable rule is not normal data.
- [ ] 2.2 New leaf arms: `in`/`notIn` with strict comparison and non-empty
      intersection when the field value is itself a list; `before`/`after`
      against the resolved instant. `equals`, `notEquals`, `withinNext`,
      `olderThan` keep their current comparisons unchanged, including
      `notEquals`'s missing/null rule (`:128-132`).
- [ ] 2.3 Combinator arms: `all` = conjunction (empty list matches), `any` =
      disjunction (empty list does NOT match), nested to `MAX_DEPTH`. Top-level
      entries stay ANDed, including combinator entries alongside field entries.

## 3. Validator

- [ ] 3.1 `NotificationAnnotationValidator::validateScheduledFilterEntry()`
      (`:1018-1102`) delegates to `ScheduledFilterParser` and returns its
      errors. Delete the "Scalar shortcut: always accepted" branch (`:1019-1021`)
      — the accept-set becomes exactly the parser's, which is exactly the
      evaluator's.
- [ ] 3.2 The `op`-key diagnostic: an array carrying `op` but not `operator`
      produces `notification-scheduled-bad-filter-operator-key` whose message
      names `operator` as the expected key and quotes the offending spelling.
- [ ] 3.3 Update the class docblocks that enumerate the old four-operator
      grammar: `ScheduledFilterEvaluator.php:5-9` and `:39-55`,
      `NotificationAnnotationValidator.php:1004-1017`.

## 4. PERF-3 seam

- [ ] 4.1 Update `ScheduledNotificationJob`'s deferred-work notes
      (`lib/BackgroundJob/ScheduledNotificationJob.php:64-67` and the rotating
      -window warning at `:347`) to name the AST as the pushdown input and
      record the two constraints from design Decision 6 (instants resolved
      before compilation; partial pushdown must equal full in-memory
      evaluation). No behaviour change in this task.

## 5. Detection

- [ ] 5.1 `tests/Unit/Service/Notification/ScheduledFilterParserTest.php` — every
      accept form and every reject form, including the four fleet dialects
      verbatim: decidesk's bare list, shillinq's `all`/`notIn`/`before`,
      openconnector's `op`/`lt` (must reject), and a canonical operator object.
- [ ] 5.2 Extend `ScheduledFilterEvaluatorTest.php` (new operators, combinators,
      empty-`all`/empty-`any` asymmetry, array-field intersection, fail-closed
      instants) and `NotificationAnnotationValidatorTest.php` (the entry that
      used to be silently accepted is now an error).
- [ ] 5.3 Gate-18 check (c) in `ConductionNL/.github`
      (`hydra-gates/scripts/lib/check_notification_dialect.py`, wired at
      `scripts/run-hydra-gates.sh:3926-4031`): classify every `scheduled` filter
      entry and every `created` filter as executable or not, hard-fail on not,
      empty scope reported as empty. Pin its operator list against a fixture set
      generated from `ScheduledFilterGrammar` so the two cannot drift silently.

## 6. Verification and rollout

- [ ] 6.1 Replay the fleet census against the new parser: the 18 decidesk and 4
      shillinq entries parse and evaluate; the 2 openconnector entries are
      rejected with the `operator` message; the 49 working entries (28 scalar,
      21 canonical) produce byte-identical results to the current evaluator.
- [ ] 6.2 Regression pass with opencatalogi and softwarecatalog installed —
      their register annotations import unchanged and their scheduled rules
      dispatch the same objects as before. Record the expected first-scan burst
      in the 22 revived rules and confirm per-object dedupe caps it.
- [ ] 6.3 Open the follow-up issues: openconnector `job.job-overdue` plus its
      two `created` rules (`openconnector_register.json:1154`, `:1940`, `:2114`);
      and the deferred `created`-vs-`scheduled` comparison-semantics convergence
      (design Decision 4) with a before/after census as its entry criterion.

## Acceptance criteria

- The set of filter shapes the validator accepts and the set the evaluator can
  execute are the same set, because both are `ScheduledFilterParser`'s. No
  second enumeration of operators exists in `lib/`.
- The 18 decidesk and 4 shillinq rules dispatch, with no edit in either repo.
- The 2 openconnector entries produce a validation error naming `operator`.
  They do not silently pass, and they do not silently match nothing.
- Every one of the 49 filter entries working before this change selects exactly
  the same objects after it.
- No filter shape reaches the evaluator unparsed; an unparsable filter matches
  nothing and says so at warning level, once per rule per scan.
- Gate-18 fails a PR that introduces a filter entry the evaluator cannot
  execute, in any fleet repo, and reports an empty scope as empty rather than
  as a pass.
- The AST is the only thing the evaluator reads; a future PERF-3 compiler needs
  no access to the raw annotation.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- New PHP files carry `@license EUPL-1.2` and `@copyright 2026 Conduction B.V.`
- `@spec` annotations point at
  `openspec/specs/notificatie-engine/spec.md` anchors.
- ADR-031: the declarative-vs-imperative argument is made in design.md, not
  assumed; no new imperative dispatch site is introduced in any app.
- No schema, migration or seed data is introduced (ADR-001 not applicable).
- The `created`-trigger comparison semantics are untouched; a diff of
  `AnnotationNotificationDispatcher::createdFilterMatches()` is empty.
