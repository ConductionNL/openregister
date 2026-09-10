## Context

See proposal.md, Why. The engine-side facts that shape the approach:

- `x-openregister-lifecycle` gates a transition through `requires`, a DI tag that
  `LifecycleGuardRegistry` resolves to a `LifecycleGuardInterface`. That tag is
  resolved at exactly ONE call site in the whole app,
  `lib/Listener/LifecycleValidationListener.php:230`.
- That listener is the single choke point for lifecycle enforcement.
  `TransitionEngine` does not check guards itself: it validates `from`, merges
  declared `inputs`, mutates the field and calls
  `ObjectService::saveObject()`, which dispatches `ObjectUpdatingEvent`, which is
  where the listener runs. So the named-action route and a direct edit of the
  lifecycle field converge on the same enforcement point, and one edit covers
  both.
- The rejection mechanism already exists. `ObjectUpdatingEvent` implements
  `StoppableEventInterface`; the listener stamps a structured error and stops
  propagation; `MagicMapper` turns that into `HookStoppedException`; the
  controllers map it to 422 (`ObjectsController`, `TransitionController:116`).
- The declarative `authorization` key, evaluated just above the guard, is the
  precedent this change follows in shape, ordering and error-code style.
- `FlowExpression` already wraps `jwadhams/json-logic-php` and exposes
  `evaluate()`, `isTrue()` and `isValid()`.
- That "single choke point" holds for STATIC transitions only. A graph-mode
  annotation declares `graph` and no `transitions`, and the listener returns
  early on an empty transition map, so graph-mode moves reach the listener and
  are waved through. This is the fact that decides the graph-mode section below,
  and it was established by reading the listener rather than assumed.

## Goals / Non-Goals

**Goals:**

- A schema author can gate a transition on the object's own data without any PHP
  in the consuming app.
- One enforcement point, so both transition routes are covered by construction
  rather than by two parallel checks that can drift.
- A malformed expression is impossible to store, because a stored one blocks
  silently and forever.
- Zero behaviour change for every schema that does not declare a `condition`.

**Non-Goals:**

- Firing a transition from a condition. That is `lifecycle-auto-transitions`,
  the next change in the chain, and it needs this change's vocabulary and data
  document to exist first.
- Extending or replacing the `@self.<field>` string dialect that a transition's
  `actions[]` entries already use for their own per-action `condition`. That key
  lives one level deeper and is untouched here.
- ENFORCING a condition on a graph-mode lifecycle. The shape is designed and
  specified below, and the annotation validator refuses it loudly, but nothing
  in this change evaluates one, because there is nowhere to evaluate it that
  covers both routes. See "Graph mode is blocked, and why".
- Fixing the underlying gap that blocks it, namely that graph-mode moves are
  ungated on the ordinary save path. That is a pre-existing defect in shipped
  code, it is not caused by this change, and inventing an enforcement layer for
  it here would be a second feature wearing this one's spec.

## Decisions

### Declarative-vs-imperative decision

Per ADR-031 this decision has to be stated honestly, because the surface reading
is backwards. This change is PHP: a listener branch, a validator branch, and
tests. It is not an app declaring behaviour in its register file.

The distinction ADR-031 draws is between an APP writing a service class and the
ENGINE providing a declarative extension for that app to use. This change is the
second. Today, an app that wants to say "a decision requires a motivation" has
exactly one option: a class implementing `LifecycleGuardInterface`, a DI tag, a
unit test and a release. Procest has several of these, each one a one-line rule
wearing a class. Those classes are precisely the anti-pattern ADR-031 lists as
"custom state-machine service", and they exist because the engine gives the
author no declarative way to express a precondition.

So the imperative code in this change is what removes imperative code from every
consuming app. It is the same trade the `authorization` key already made, and
ADR-031's exception (1) names the route explicitly: when OR's extension is
missing, the extension is what should be built. `requires` stays, unchanged and
supported, for the non-trivial checks ADR-031 calls a legitimate seam: reading
external state, cross-schema lookups, anything with IO. A JSONLogic condition is
for the rules that never needed a class.

### Reuse `FlowExpression`, add no dialect

JSONLogic through `FlowExpression` is already this codebase's expression
language: flow router and switch edges, `FilterNode`, and `CaseSentryEvaluator`,
whose header refuses to add a fourth condition dialect to a fleet already paying
for three (openregister#2787). A lifecycle condition is the same kind of
question, so it gets the same answer and the same facade, custom operators
included. Nothing new is invented, and `isValid()` comes with it.

Alternative considered: a small comparison dialect of the kind
`LifecycleActionExecutor` already parses for action conditions
(`@self.<field> == '<value>'`). Rejected. It reads well for exactly one shape
and then needs an operator, then a negation, then a boolean combinator, and
arrives at a worse JSONLogic. It is also the dialect openregister#2787 was
written against.

### The data document is object-shaped, not flow-shaped

The condition is evaluated against `object`, `previous`, `user` and
`transition`, and deliberately NOT against `FlowExpression::dataFor()`'s
`json` / `binary` / `itemIndex` / `itemCount` / `context` / `subject`. A schema
author writing a lifecycle rule is looking at an object in a register, not at an
item flowing through a graph. `{"var": "object.motivering"}` reads the way the
schema reads; `{"var": "json.motivering"}` would require the author to hold a
concept from a subsystem they are not using. `dataFor()` is a convenience for
flow nodes, not the contract of `evaluate()`, which takes any array, so nothing
about reuse forces the flow shape here.

The cost is that the two documents differ, so an author moving between a flow
condition and a lifecycle condition must know which one they are in. That is
accepted: the alternative asks every schema author to learn the flow shape so
the minority who write both can avoid one lookup.

### Evaluate after `authorization`, before `requires`

The order is `authorization`, then `condition`, then `requires`. A `requires`
guard is app code that can read external state, take a lock, or write a log
line. A caller whose precondition is not met should be refused before any of
that happens, which is the same reason `authorization` was placed above
`requires` when it was added. It is also the cheap check first: JSONLogic is
in-process and allocation-bounded, guard resolution goes through the DI
container.

### A condition failure is 422, not 403

`lifecycle-guard-denied` maps to 403 and `lifecycle-transition-unauthorized` is
authorization-shaped. `lifecycle-condition-unmet` is neither: the caller may be
perfectly entitled to make this transition, the object is simply not ready for
it. That is an unprocessable entity, so it takes the 422 path the other
data-shaped rejections (`lifecycle-invalid-value`,
`lifecycle-invalid-transition`) already take. No controller change is needed for
this; the existing `HookStoppedException` mapping already produces it.

### Graph mode is blocked, and why

Graph mode is in scope for this design and out of scope for its implementation,
which is an uncomfortable pair, so here is the whole chain of reasoning.

**The shape.** A graph-mode annotation declares a `graph` block and no
`transitions` map. `TransitionEngine::deriveGraphActions()` synthesises
`move-to-<uuid>` actions at runtime from FK-scoped siblings, so there is no
per-transition object anywhere to hang a `condition` on. The only coherent
placement is on the `graph` block itself, applying to every derived move, with
the derived move exposed to the expression through the same `transition` key
(`action` being the synthesised `move-to-<uuid>`, `from` and `to` the sibling
UUIDs). A per-target condition would need a map keyed by sibling UUID, which
puts object identifiers in a schema and defeats the point of deriving the graph
from data. So `graph.condition` is the right shape, and it is the one specified.

**The blocker.** There is no enforcement point for it. Enforcement of a
graph-mode move exists ONLY inside `TransitionEngine`: `deriveGraphActions()` is
private and is called by `availableActions()` and by `transition()`. On the
ordinary save path, `LifecycleValidationListener` reads
`$annotation['transitions']`, finds it empty for a graph-mode annotation, and
returns early through the "app-managed, nothing to enforce" branch. Nothing else
in `lib/` reads the `graph` key: it appears in `TransitionEngine`,
`LifecycleAnnotationValidator` and `FlowController` only. The listener does not
know graph mode exists.

**The consequence, which is a finding in its own right.** Under graph mode a
direct edit of the lifecycle field through `ObjectService::saveObject()` is
ungated today. Any value can be written into the status field of a graph-mode
object without passing any derivation. That is a pre-existing gap in shipped
code, not something this change introduces, and it is worth a decision of its
own outside this change.

**Why refusing beats half-enforcing.** A `graph.condition` honoured only on the
`TransitionEngine` route would be silently absent on the other one. The author
who wrote it believes the state is unreachable without the condition holding; it
is reachable with one PATCH. A partial gate is worse than no gate, because no
gate is at least honest about what it does not do. This codebase already takes
that posture: `LifecycleActionExecutor` throws for a declared action with no
registered handler rather than skipping it, on the stated ground that a silent
no-op is the defect being eliminated. So the validator refuses `graph.condition`
with `lifecycle-condition-graph-unsupported`, which costs one branch and one
test and cannot be mistaken for working.

**What unblocks it.** Graph-mode enforcement on the save path: the listener
gaining graph awareness, deriving the candidate set the same way the engine does
(which requires lifting `deriveGraphActions()` out of `TransitionEngine`'s
private surface, or moving the derivation into a shared collaborator both call).
That belongs to `lifecycle-graph-enforcement`, the third link in this chain,
which owns the gap. Once it lands, this requirement is replaced by the enforcing
one, and the `graph.condition` shape above is what it should implement.

**The alternative considered, and rejected.** Accept `graph.condition` and
enforce it on the `TransitionEngine` route only, on the argument that partial
gating is strictly more gating than today's zero. Rejected on the ground above:
the failure is silent and points the wrong way, and a security-adjacent gate is
the last place to accept "better than nothing". It is a cheap decision to
overrule if the coverage argument is judged to outweigh the silence.

### `message` accepts a per-locale map, and a string stays valid

`message` is either a non-empty string or a per-locale map with an optional
`defaultLocale`, copied key for key from the shape
`x-openregister-notifications` already uses for `subject` and `message` and
validated the way `NotificationAnnotationValidator::validateMessage()` validates
it, down to returning one canonical error code for every malformed shape.
ADR-007's nl+en minimum is the driver: a refusal a citizen-facing handler reads
should not be in a language chosen by whoever wrote the schema.

The string shorthand STAYS valid, deliberately. Most refusal messages are
written by a Dutch team for a Dutch product and gain nothing from a map, and
forcing a two-key object on every one-line rule reintroduces exactly the
ceremony this change exists to remove. The rule is: string when a single
language is genuinely enough, map when it is not, and the same key accepts both
so upgrading one message later touches only that message. Resolution is
caller-language first, then `defaultLocale`, then `en`, then the first declared
locale, so a partially translated map degrades to something readable rather than
to an empty string.

### The engine's fallback is translated; the author's message is not

The generic refusal (a transition declares a `condition` and no `message`) is
engine text, so it goes through `IL10N` and gets a translatable string. The
author's `message` is author content and passes through untouched: translating a
schema author's Dutch sentence against OpenRegister's own catalogue would find
no key and would be wrong if it did.

The honest consequence: `lifecycle-condition-unmet` becomes the ONLY translated
lifecycle rejection. `lifecycle-invalid-value`, `lifecycle-invalid-transition`
and `lifecycle-guard-denied` are all English `sprintf` literals today, and this
change leaves them that way, so the listener will hold four rejection paths of
which one is translated and three are not. That is an inconsistency shipped
knowingly.

Bringing the other three along belongs in a follow-up, not here, for two
reasons. It is not this change's blast radius: each of the three has consumers
asserting on message text (the guard message is supplied by app code and merely
passed through, which raises its own question about what translating it would
even mean), so touching them means touching their tests and their consumers.
And mixing an i18n sweep into a feature change is the `mixed` shape ADR-032
rejects. ADR-025 holds the i18n source-of-truth rules the follow-up has to
satisfy. Recorded here rather than left for someone to discover from a
half-Dutch error envelope.

### The mock-register example, and the seed-data question

The worked example goes on the `dataSubjectRequest` schema's existing `refuse`
transition in `lib/Settings/openregister_mock_register.json`: refusing a
data-subject request requires a `denialGround` that is set and is not
`not-applicable`. It is a real statutory-shaped rule, it is exactly the one-line
rule that would otherwise be a PHP guard, and it sits in the file developers
already read for annotation examples. It carries a per-locale `message` so the
map shape is demonstrated too.

On the seed-data rule: this does NOT need a Seed Data section. It annotates an
existing lifecycle block on an existing schema. No schema is added, no property
is added, no register is added, and no object is seeded; the diff is a pure
insertion of two keys. Per ADR-032 a `kind: code` change may incidentally touch
declarative JSON when the centre of mass is code, which it is here: the listener
and the validator are the change, and the register edit is documentation that
happens to be executable. This paragraph exists because the rule says to state
the judgement rather than skip it silently.

### Save-time validation is the load-bearing decision

`FlowExpression::isTrue()` returns FALSE for anything it cannot evaluate. That
is right for a flow edge, where an untaken branch is a small loss. For a
blocking condition it is a trap: a typo becomes a transition that can never be
made, with no error in any log, nothing red in CI, and nothing in the schema
that looks wrong. The complaint arrives weeks later as "the button does
nothing".

So `LifecycleAnnotationValidator` refuses a malformed `condition` at schema-save
time via `FlowExpression::isValid()`, with the error code
`lifecycle-condition-malformed`, beside the existing `requires` shape check.

Two limits of `isValid()` are worth stating rather than discovering later:

1. **It accepts scalars.** Its own first branch returns true for anything that
   is not an array, because a scalar is a literal. A string condition therefore
   passes `isValid()` and then evaluates to a truthy literal at runtime,
   authorizing every transition it was written to block. That is fail-OPEN, the
   one failure direction worse than the silent-false it is guarding against, and
   it is exactly what an author copying the `actions[].condition` string dialect
   up one level would write. The validator therefore requires the
   transition-level `condition` to be a rule OBJECT and refuses a scalar with
   the same code, with a message pointing at the JSONLogic form.
2. **It cannot catch a mistyped variable path.** `{"var": "object.motivring"}`
   is a well-formed rule; JSONLogic resolves an unknown path to null, which is a
   legal evaluation, not an error. Save-time validation catches structural
   breakage (unknown operator, malformed rule object), not a spelling mistake in
   a field name. Mitigation is diagnosis rather than prevention: the runtime
   refusal names the transition, the lifecycle field and the declared `message`,
   and the listener logs at debug level with the transition action when a
   condition refuses, so an author can see WHICH rule refused. Validating the
   var path against the schema's declared properties is a real follow-up, but it
   is not free (paths may address nested objects and array members) and it is
   not in this change.

### Runtime stays fail-closed anyway

A stored expression that cannot be evaluated for the object at hand refuses the
transition. Save-time validation is what keeps that from happening, not a
substitute for it: a schema can be written into the database by an import path
that skips validation, and a custom operator can behave differently against real
data than against the empty document `isValid()` uses. Fail-closed is the only
safe default for a gate.

### Tests are mutation-checked, and that is part of the definition of done

A test that saves a valid object and asserts the transition succeeds passes
whether or not the condition is evaluated at all. A test that asserts a
malformed condition is refused at save time passes trivially if it asserts the
wrong thing. Both are the shape of test that reports green on a broken engine.
Every test for the malformed and fail-closed paths is therefore proven RED first
by breaking the thing it guards (removing the validator branch, inverting the
refusal), then restored. `tasks.md` makes this a step, not a hope.

### `occ` and any session-less caller sees an empty `user`

`occ` has no session, so every `ObjectService` call made from the CLI runs as
Anonymous. A condition reading `user.uid` gets an empty string under CLI, and
`user.groups` an empty list; the same holds for a background job or an import
that does not carry a user. Because the condition is fail-closed, a
membership-shaped condition such as
`{"in": ["vergunningverleners", {"var": "user.groups"}]}` REFUSES every CLI
transition. That is the correct default and the same posture the rest of the
lifecycle stack takes (the `authorization` gate already denies an anonymous
caller), but it is a sharp edge for anyone scripting a bulk transition, so it is
documented on the annotation reference: a condition that must hold for machine
callers should be written against `object` and `previous`, not `user`. Identity
questions belong in `authorization`, which is what it is for.

### Creation is out of the picture by construction

`LifecycleValidationListener` is registered only on `ObjectUpdatingEvent`;
`ObjectCreatingEvent` goes to `LifecycleInitialStateListener`, which seeds the
declared `initial` state. There is no transition on create, so there is nothing
for a condition to gate, and the listener already returns early when the event
carries no prior object. No change is needed to preserve that; it is recorded
here so nobody later reads "conditions gate transitions" as "conditions gate
creates".

## Risks / Trade-offs

- **A typo becomes a permanent silent block** → the reason save-time validation
  is a requirement of its own rather than a nicety. Covers structural breakage
  and the fail-open scalar case; does not cover a mistyped field path, which is
  mitigated by the refusal naming the transition and by a debug log line.
- **An author writes a scalar condition and opens the gate instead of closing
  it** → refused at schema-save time as malformed, because `isValid()` alone
  would accept it.
- **Two `condition` keys in one annotation, in two dialects** (JSONLogic on the
  transition, `@self.<field>` inside `actions[]`) → the shapes are disjoint by
  type and by nesting depth, the validator refuses the wrong one where it can,
  and the annotation reference documents both side by side. Unifying them is a
  separate change with its own back-compat problem, not a side effect of this
  one.
- **Evaluation cost on the save path** → JSONLogic runs in-process with no IO,
  and only when the lifecycle field actually changed AND a transition matched
  AND that transition declares a condition. Every other save is unaffected.
- **A condition can encode a rule the schema does not otherwise express**, so a
  reader of the properties alone cannot see why a transition refuses → the
  `message` key exists to make the refusal legible to the person hitting it, and
  it is worth writing even though it is optional.
- **A guard and a condition on the same transition** → both apply, condition
  first. That is intentional: a rule may be partly declarative and partly
  external.
- **An author expects a graph-mode condition to work and finds it refused** →
  that is the intended outcome, and the refusal message says why. The worse
  version of this risk, an author who expects it to work and is told it does,
  is what the refusal exists to prevent.
- **Graph-mode lifecycles stay ungated on the ordinary save path** → not
  introduced here and not fixed here, but now written down with the code
  references, so it stops being invisible. It is the dependency for the
  graph-mode requirement in this change's spec.
- **One translated rejection code beside three untranslated ones** → knowingly
  shipped, follow-up named, ADR-025 cited. The alternative, leaving the new one
  untranslated for symmetry, makes the inconsistency invisible instead of
  visible and adds one more literal to the eventual sweep.

## Migration Plan

Purely additive. No migration, no schema rewrite, no data change: an annotation
with no `condition` key takes exactly the path it takes today, and the validator
only reports on a key that is present. Rollback is reverting the two source
files; a schema saved in the meantime carrying a `condition` simply stops being
enforced, which fails open, so a rollback should be paired with removing any
condition already declared in a register file.

The consuming-app migration (Dossiq retiring one-line guard classes such as the
statutory-deadline check) is opportunistic per ADR-031 and is not a task in this
change.

## Open Questions

- `fk-graph-lifecycle-transitions` is listed in-progress with every task
  unticked, yet its engine code is already in `lib/` and its requirements are
  already in the main `object-lifecycle` spec. That change should be archived on
  its own merits. This change does not wait on it: the `graph` key it reads in
  order to refuse a condition already exists in shipped code, so `depends_on` is
  empty rather than blocking real work on another change's paperwork.
- Should the validator check a condition's `var` paths against the schema's
  declared properties? It would close the mistyped-field-name gap, but nested
  and array paths make it non-trivial and a false positive would refuse a valid
  schema. Deferred to its own change.
