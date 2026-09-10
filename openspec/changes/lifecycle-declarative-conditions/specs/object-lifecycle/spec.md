## ADDED Requirements

### Requirement: A lifecycle transition MAY declare a declarative condition that gates it

@e2e exclude backend lifecycle listener — covered by PHPUnit

A transition declared in `x-openregister-lifecycle.transitions` MAY carry an
optional `condition`, whose value is a JSONLogic rule object, and an optional
`message`. A `message` SHALL be either a non-empty string or a per-locale map
(`{"nl": "…", "en": "…"}`, optionally carrying `defaultLocale`), which is the
same shape `x-openregister-notifications` already uses for its `subject` and
`message`. Per ADR-007 a map SHOULD declare at least `nl` and `en`.

When a matched transition declares a `condition`, OpenRegister SHALL evaluate it
on the `ObjectService::saveObject()` path before the write. When the condition
holds, the transition SHALL proceed exactly as it does without one. When the
condition does not hold, the save SHALL be refused with the structured error
code `lifecycle-condition-unmet`, the lifecycle field SHALL NOT be mutated, and
no object write SHALL occur.

The refusal SHALL carry the transition's `message` when one is declared. A
string `message` SHALL be used verbatim. A map `message` SHALL be resolved to
the caller's language as reported by `IL10N::getLanguageCode()`, falling back to
the map's `defaultLocale`, then to `en`, then to the first declared locale. The
language SHALL come from `IL10N` rather than from `Accept-Language` negotiation,
so the message follows the user's configured language. An author's `message` SHALL pass through
untouched and SHALL NOT be translated by the engine. When a transition declares
no `message`, the refusal SHALL carry the engine's own generic message naming
the transition action and the lifecycle field, and that message SHALL be
translated through the app's translation layer. The refusal SHALL NOT expose the
expression itself to the caller.

The gate SHALL be reached by BOTH transition routes, because both converge on
the same save path: the named-action route (`POST /api/objects/{id}/transition`)
and a direct edit of the lifecycle field through an ordinary object save. A
transition WITHOUT a `condition` key SHALL behave exactly as before; the key is
additive and never required.

#### Scenario: A condition that holds lets the transition through
- **GIVEN** a transition `beslissen` with `from: ["in-behandeling"], to: "besloten"` and `condition: { "!!": { "var": "object.motivering" } }`
- **AND** an object whose `motivering` is a non-empty string
- **WHEN** the object's lifecycle field is saved as `besloten`
- **THEN** the transition MUST be applied and no condition error MUST be raised

#### Scenario: A condition that does not hold refuses the save
- **GIVEN** the same transition
- **AND** an object whose `motivering` is absent or empty
- **WHEN** the transition is attempted
- **THEN** the save MUST be refused with the structured error code `lifecycle-condition-unmet`
- **AND** the lifecycle field MUST retain its previous value
- **AND** no object write MUST occur

#### Scenario: The declared message is what the caller sees
- **GIVEN** a transition declaring `message: "Een besluit vereist een motivering."` whose condition does not hold
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry that message verbatim
- **AND** the refusal MUST NOT contain the JSONLogic expression

#### Scenario: A per-locale message is resolved to the caller's language
- **GIVEN** a transition declaring `message: { "nl": "Een besluit vereist een motivering.", "en": "A decision requires a motivation." }` whose condition does not hold
- **AND** a caller whose language is `nl`
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry the `nl` string verbatim
- **AND** the same refusal for a caller whose language is `de`, which the map does not declare, MUST fall back to `defaultLocale` when declared, otherwise to `en`, otherwise to the first declared locale

#### Scenario: A refused condition without a message still names the transition
- **GIVEN** a transition declaring a `condition` and no `message` whose condition does not hold
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry code `lifecycle-condition-unmet` and the engine's generic message naming the transition action and the lifecycle field
- **AND** that generic message MUST be produced through the app's translation layer, not as an untranslated literal

#### Scenario: The named-action route is gated identically to a direct field edit
- **GIVEN** a transition whose condition does not hold
- **WHEN** the transition is requested through `POST /api/objects/{id}/transition`
- **THEN** the request MUST be answered with HTTP 422 and the code `lifecycle-condition-unmet`
- **AND** the same attempt made by editing the lifecycle field through an ordinary object save MUST be refused with the same code

#### Scenario: A transition without a condition is unaffected
- **GIVEN** a transition declaring no `condition` key
- **WHEN** an otherwise valid transition is attempted
- **THEN** no condition MUST be evaluated and the transition MUST proceed

### Requirement: The condition data document exposes the object, its previous state, the caller and the transition

@e2e exclude backend lifecycle listener — covered by PHPUnit

A lifecycle condition SHALL be evaluated against a data document with exactly
four top-level keys:

- `object`: the object data as it would be written, including the new lifecycle
  field value;
- `previous`: the stored object data as it is before the write, including the
  old lifecycle field value;
- `user`: the acting caller, as `uid` (an empty string when there is no session
  user) and `groups` (the caller's Nextcloud group ids, an empty list when there
  is no session user);
- `transition`: the matched transition, as `action` (its declared name), `from`
  (the old lifecycle value) and `to` (the new lifecycle value).

The document SHALL NOT carry the flow engine's `json`, `binary`, `itemIndex`,
`itemCount`, `context` or `subject` keys: a schema author writing a lifecycle
rule is looking at an object, not at a flow item. A reference to a key that the
document does not carry SHALL resolve to null and therefore refuse the
transition, per the fail-closed rule below.

#### Scenario: A condition reads a field of the object being written
- **GIVEN** a condition `{ "!!": { "var": "object.motivering" } }`
- **WHEN** the transition is evaluated for an object carrying a non-empty `motivering`
- **THEN** the condition MUST hold

#### Scenario: A condition compares the new value against the previous one
- **GIVEN** a condition referencing `previous.bedrag` and `object.bedrag`
- **WHEN** the transition is evaluated
- **THEN** `previous.bedrag` MUST resolve to the stored value and `object.bedrag` to the value being written

#### Scenario: A condition reads the caller's group membership
- **GIVEN** a condition `{ "in": ["vergunningverleners", { "var": "user.groups" }] }`
- **AND** a caller belonging to that Nextcloud group
- **WHEN** the transition is evaluated
- **THEN** the condition MUST hold
- **AND** for a caller with no session, `user.uid` MUST be an empty string and `user.groups` MUST be an empty list

#### Scenario: A condition reads the matched transition
- **GIVEN** a condition referencing `transition.action`, `transition.from` and `transition.to`
- **WHEN** the transition `beslissen` moves `in-behandeling` to `besloten`
- **THEN** those keys MUST resolve to `beslissen`, `in-behandeling` and `besloten` respectively

#### Scenario: A flow-shaped reference does not resolve
- **GIVEN** a condition referencing `json.motivering`
- **WHEN** the transition is evaluated
- **THEN** the reference MUST resolve to null and the transition MUST be refused with `lifecycle-condition-unmet`

### Requirement: The condition is evaluated after the authorization gate and before the requires guard

@e2e exclude backend lifecycle listener — covered by PHPUnit

For a matched transition, OpenRegister SHALL evaluate the declarative gates in a
fixed order: the `authorization` list first, then the `condition`, then the
`requires` guard. A failure at any stage SHALL refuse the save immediately and
SHALL NOT evaluate any later stage.

The ordering is normative, not incidental. A `requires` guard is app code that
may read external state, write a log line or take a lock, so a caller whose
precondition is not met MUST be refused before any such side channel runs, for
the same reason `authorization` is already evaluated before `requires`.

#### Scenario: An unauthorized caller is refused before the condition runs
- **GIVEN** a transition declaring both a non-empty `authorization` list and a `condition`
- **AND** a caller who satisfies neither
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry code `lifecycle-transition-unauthorized`
- **AND** the condition MUST NOT be evaluated

#### Scenario: An unmet condition is refused before the guard is resolved
- **GIVEN** a transition declaring both a `condition` that does not hold and a `requires` guard tag
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry code `lifecycle-condition-unmet`
- **AND** the guard MUST NOT be resolved and its `check()` MUST NOT be invoked

#### Scenario: A met condition still runs the guard
- **GIVEN** a transition declaring a `condition` that holds and a `requires` guard that denies
- **WHEN** the transition is attempted
- **THEN** the refusal MUST carry code `lifecycle-guard-denied` with the guard's message

### Requirement: A malformed condition MUST be refused at schema-save time and MUST never fail open at runtime

@e2e exclude backend lifecycle listener — covered by PHPUnit

An expression that cannot be evaluated is treated as FALSE by OpenRegister's
expression engine. For a blocking condition, that turns an author's typo into a
permanent, silent, unexplained refusal of that transition, with no error raised
anywhere and nothing in the schema to look at. The engine MUST therefore refuse
such an expression at the moment it is authored.

At schema-save time, when a transition declares a `condition`, OpenRegister
SHALL validate it and SHALL return the structured error `lifecycle-condition-malformed`
when it is not a well-formed expression, naming the offending transition. A
transition-level `condition` SHALL be a JSONLogic rule object; a scalar,
including a string in the `@self.<field> == '<value>'` form that a transition's
`actions[]` entries use, SHALL be refused with the same code, because such a
string would evaluate as a truthy literal and silently authorize every
transition it was meant to gate. When a
transition declares a `message` that is neither a non-empty string nor a
per-locale map carrying at least one locale key with a non-empty string value,
OpenRegister SHALL return `lifecycle-message-malformed`; a `defaultLocale` that
names a locale the map does not declare SHALL be refused with the same code.
Every malformed `message` shape SHALL return that one code, so an author has a
single canonical error to look up, exactly as the notification dialect returns
one code for a broken body template. Both errors SHALL be
collected and returned alongside the annotation's other validation errors rather
than thrown, and SHALL cause the schema save to be refused, exactly as every
other lifecycle annotation error does.

At runtime the evaluation SHALL be fail-closed: an expression that cannot be
evaluated SHALL refuse the transition with `lifecycle-condition-unmet` and SHALL
NOT allow it. Save-time validation is what keeps that runtime rule from ever
firing on a stored schema; it is not a substitute for it.

Every test covering this requirement SHALL be proven to fail against a
deliberately broken condition before it is accepted. A test that asserts only
the passing case is green whether or not the engine refuses malformed
expressions, and therefore proves nothing about this requirement.

#### Scenario: A malformed condition is refused when the schema is saved
- **GIVEN** a transition declaring a `condition` that is not a well-formed expression, such as an unknown operator
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with an error carrying code `lifecycle-condition-malformed` and naming the transition
- **AND** the malformed expression MUST NOT be stored

#### Scenario: A well-formed condition passes save-time validation
- **GIVEN** a transition declaring `condition: { "!!": { "var": "object.motivering" } }`
- **WHEN** the schema is saved
- **THEN** no condition error MUST be returned
- **AND** the annotation's other validation rules MUST be unaffected

#### Scenario: An action-dialect condition string on a transition is refused
- **GIVEN** a transition declaring `condition: "@self.settlementMode == 'reimbursable'"`, the string form used by an action envelope one level deeper
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with `lifecycle-condition-malformed` naming the transition
- **AND** the message MUST point the author at the JSONLogic rule-object form
- **AND** the `condition` an entry of `actions[]` declares MUST keep its existing string dialect and its existing behaviour, unchanged

#### Scenario: A malformed message is refused at schema-save time
- **GIVEN** a transition declaring a `message` that is a number, an empty string, an empty map, a map whose only values are empty strings, or a map whose `defaultLocale` names an undeclared locale
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with an error carrying code `lifecycle-message-malformed`

#### Scenario: Both message shapes pass save-time validation
- **GIVEN** one transition declaring `message: "Een besluit vereist een motivering."` and another declaring `message: { "nl": "…", "en": "…" }`
- **WHEN** the schema is saved
- **THEN** neither MUST produce a `lifecycle-message-malformed` error

#### Scenario: A condition that cannot be evaluated at runtime refuses the transition
- **GIVEN** a stored transition whose condition cannot be evaluated for the object at hand
- **WHEN** the transition is attempted
- **THEN** the save MUST be refused with `lifecycle-condition-unmet`
- **AND** the transition MUST NOT be applied

#### Scenario: The malformed-condition test is proven to fail before it is accepted
- **GIVEN** the PHPUnit test asserting that a malformed condition is refused at schema-save time
- **WHEN** the validation of the `condition` key is deliberately removed
- **THEN** that test MUST fail
- **AND** the same MUST hold for the runtime fail-closed test when the refusal is deliberately inverted

### Requirement: A condition on a graph-mode lifecycle MUST be refused, not partially enforced

@e2e exclude backend lifecycle listener — covered by PHPUnit

A graph-mode lifecycle declares a `graph` block and no `transitions` map, so
there is no per-transition object on which to declare a `condition`. The only
available shape is a `condition` (and `message`) on the `graph` block itself,
applying to every move the graph derives.

That shape SHALL NOT be enforced in this change, and OpenRegister SHALL refuse
it: when an annotation declares a `graph` block carrying a `condition`, the
schema save SHALL be refused with the structured error code
`lifecycle-condition-graph-unsupported`, naming the schema and stating that
graph-mode conditions are not yet enforced.

The refusal is the requirement, and the reason is enforcement coverage. A
graph-mode move is validated only inside `TransitionEngine`, which derives the
candidate set and rejects anything outside it. On the ordinary save path there
is no graph enforcement at all: `LifecycleValidationListener` reads
`transitions`, finds it empty for a graph-mode annotation, and returns without
validating anything. A condition accepted on the `graph` block could therefore
only ever hold on the named-action route and would be silently absent when the
same lifecycle field is written directly. A gate that holds on one route and not
the other is worse than no gate, because the author believes the state is
unreachable. OpenRegister already takes this posture for a declared action that
resolves to no handler: it fails loudly rather than skipping silently.

This requirement SHALL be replaced by the enforcing behaviour once graph-mode
moves are validated on the ordinary save path. Until then the refusal keeps the
gap visible to the author who would otherwise depend on it.

#### Scenario: A condition on a graph block is refused at schema-save time
- **GIVEN** an annotation declaring a `graph` block that carries a `condition`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with code `lifecycle-condition-graph-unsupported`
- **AND** the message MUST state that graph-mode conditions are not yet enforced

#### Scenario: A graph-mode annotation without a condition is unaffected
- **GIVEN** an annotation declaring a `graph` block and no `condition`
- **WHEN** the schema is saved
- **THEN** it MUST validate exactly as it does today, with no new error

#### Scenario: A static transition condition is unaffected by graph-mode refusal
- **GIVEN** a schema declaring both a non-empty `transitions` map with a `condition` and a `graph` block with no `condition`
- **WHEN** the schema is saved
- **THEN** no `lifecycle-condition-graph-unsupported` error MUST be raised
- **AND** the static transition's condition MUST be validated and enforced as specified above
