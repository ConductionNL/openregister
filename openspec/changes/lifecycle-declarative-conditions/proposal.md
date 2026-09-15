---
kind: code
depends_on: []
chain:
  - lifecycle-declarative-conditions   # this spec
  - lifecycle-auto-transitions         # next in chain
  - lifecycle-graph-enforcement        # closes the graph-mode save-path gap
---

## Why

ADR-031 names `x-openregister-lifecycle` as the declarative replacement for
"state machines, transition guards" and records the fleet's failure mode: apps
keep writing service classes for behaviour the schema engine could express. The
lifecycle annotation still leaves one hole exactly there. A transition can be
gated, but only by PHP: `requires` holds a DI tag that
`LifecycleGuardRegistry` resolves to a `LifecycleGuardInterface`, so the
cheapest possible precondition costs a consuming app a class, a DI registration,
a unit test and a release. Dossiq pays that price today for one-line rules such
as "the statutory deadline has not passed"
(`procest/lib/Lifecycle/BezwaarDeadlineGuard.php`). ADR-031 calls the PHP guard a
legitimate seam for non-trivial checks, and it is; the problem is that it is
also the *only* seam, so trivial checks go through it too.

The declarative `authorization` key on a transition already proved the shape.
It was added so procest's role routing needed no bespoke guard, and it worked:
group and role gating became schema metadata. This change does the same thing
for preconditions on the object's own data.

## What Changes

- **New optional `condition` key on a lifecycle transition.** Its value is a
  JSONLogic rule object. When the transition is matched and the condition does
  not hold, the save is refused with the structured error code
  `lifecycle-condition-unmet` and the object is not mutated.
- **New optional `message` key on a lifecycle transition.** Either a non-empty
  string or a per-locale map (`{"nl": "…", "en": "…"}` with an optional
  `defaultLocale`), the exact shape the `x-openregister-notifications` dialect
  already uses for `subject` and `message`. It is surfaced as the rejection's
  human-readable message, resolved to the caller's language, so a schema author
  can say why in domain language instead of leaking an expression at the caller.
- **Evaluation point.** `LifecycleValidationListener` evaluates the condition
  after the declarative `authorization` gate and immediately BEFORE resolving
  the `requires` guard, so an unmet precondition is reported without any guard
  side channel running first. Because `TransitionEngine` reaches this listener
  through `ObjectService::saveObject()` rather than checking guards itself, one
  edit covers both the named-action route (`POST /transition`) and the direct
  lifecycle-field edit route.
- **The condition's data document** is `object` (the new object data),
  `previous` (the stored object data), `user` (`uid` plus `groups`), and
  `transition` (`action`, `from`, `to`).
- **The engine's own fallback message is translated** through `IL10N` for the
  case where a transition declares a `condition` and no `message`. A declared
  `message` is author content and passes through untouched.
- **Schema-save validation.** `LifecycleAnnotationValidator` rejects a malformed
  `condition` with `lifecycle-condition-malformed` and a malformed `message`
  with `lifecycle-message-malformed`. This is not cosmetic: `FlowExpression`
  answers FALSE for any expression it cannot evaluate, so an unvalidated typo
  would become a permanent, silent, unexplained block on that transition.
- **Graph-mode conditions are declared unsupported, loudly, rather than half
  enforced.** A `condition` on a `graph` block is refused at schema-save time
  with `lifecycle-condition-graph-unsupported`. Graph-mode moves have no
  enforcement point on the ordinary save path at all today (see design.md,
  "Graph mode is blocked, and why"), so a graph condition could only be honoured
  on the `TransitionEngine` route and would be silently absent on the other. An
  author must not be able to deploy a gate that holds on one route and not the
  other. **BLOCKING FINDING**, reported rather than worked around.
- **No new expression dialect.** The condition is evaluated through the existing
  `FlowExpression` facade over `jwadhams/json-logic-php`, the same engine behind
  flow router edges, `FilterNode` and `CaseSentryEvaluator`. Per
  openregister#2787 the fleet does not add a fourth condition dialect.
- Fully additive. A transition with no `condition` behaves exactly as it does
  today, and `requires` guards keep working unchanged beside it.
- **Out of scope, next in the chain.** `lifecycle-auto-transitions` will let a
  schema FIRE a transition when a condition becomes true, with a per-transition
  sync or async execution mode and a re-entrancy cap. That change needs the
  condition vocabulary this one defines, which is why it is second. Nothing in
  this change evaluates a condition to trigger anything; a condition here only
  ever refuses.

## Capabilities

### New Capabilities
<!-- none, this extends an existing capability -->

### Modified Capabilities
- `object-lifecycle`: a lifecycle transition MAY declare a declarative
  JSONLogic `condition` and a `message`. The listener gains a fourth rejection
  code beside `lifecycle-invalid-value`, `lifecycle-invalid-transition` and
  `lifecycle-guard-denied`, and the annotation validator gains three schema-save
  error codes, one of which refuses the graph-mode form outright.

## Impact

- `lib/Listener/LifecycleValidationListener.php`: condition evaluation between
  the `authorization` gate and the `requires` guard, a `FlowExpression` data
  document built from the event's old and new objects and the session user,
  locale resolution for a declared `message`, and an `IL10N` fallback message.
- `lib/Service/Lifecycle/LifecycleAnnotationValidator.php`: shape and validity
  checks for `condition` and `message` next to the existing `requires` check,
  plus the graph-mode refusal.
- `lib/Settings/openregister_mock_register.json`: a worked `condition` and
  per-locale `message` on the `dataSubjectRequest` schema's existing `refuse`
  transition, so the example sits where developers already look. An annotation
  on an existing lifecycle block, adding no schema and no objects.
- `tests/Unit/Listener/` and `tests/Unit/Service/Lifecycle/`: PHPUnit coverage,
  mutation-checked against a deliberately broken expression.
- API surface: `POST /api/objects/{id}/transition` and the ordinary object save
  endpoints can now answer 422 with `lifecycle-condition-unmet`. No route,
  signature or database change.
- Consuming apps: no change required. Dossiq is the first intended consumer and
  can retire one-line guard classes opportunistically, which is ADR-031's
  migration rule, not a task here.
- `depends_on: []`: this change reads the `graph` key only in order to REFUSE a
  condition on it, and that key already exists in shipped `lib/` code. Declaring
  a dependency on `fk-graph-lifecycle-transitions` would block this change on
  that one's remaining paperwork rather than on any code it needs.
- `lifecycle-graph-enforcement` is the third link in the chain and owns the
  graph-mode save-path gap this change documents but does not close.
- No new dependency: `jwadhams/json-logic-php` is already required.
