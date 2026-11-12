# Hand over: the rules engine, for the apps that consume it

This is task 6.1. It is written for the dossiq lane building
`field-rules-declared`, and for decidiq, humaniq and pipelinq, which get the
same surface over their own schemas without writing one.

Read it as the contract. Nothing here is a plan: every endpoint and every word
in the vocabulary is shipped on `development`.

## The one thing to take from it

A consuming app declares rules on its schemas and reads them back. It does not
build a rules engine, a rule screen or a second run log. ADR-022: one engine in
the platform layer, a leaf declares.

## The rule id

`<kind>:<schemaSlug>:<key>`, for example `calculation:bezwaar:uiterlijkeDatum`.

Nothing allocates it. It is derived from three facts every time, so a rule keeps
its id across reads, restarts and machines, and the run log keys on it without a
registry. A colon is legal unescaped in a URL path segment and illegal in a
schema slug and a property name, so the id round-trips through a route.

## The closed sets

Read them from `GET /api/rules/vocabulary` rather than copying them. A consumer
that reads the endpoint renders a rule kind it has never seen; a consumer that
copied the list renders a blank.

**Kinds**, in the order the save pipeline evaluates them:

| kind | order | read from | what it does |
| --- | --- | --- | --- |
| `calculation` | 1 | `x-openregister-calculations` | Derives a value and writes it before the object is stored. |
| `stateFieldRule` | 2 | `x-openregister-lifecycle.states` | Hides, freezes or requires a property while the object is in one state. |
| `lifecycleCondition` | 3 | `x-openregister-lifecycle.transitions` | Decides whether a transition may proceed. |
| `flow` | 4 | `openregister_flow_triggers` | Runs a flow after the object is stored. |

**Actions**: `setValue`, `hideField`, `readOnlyField`, `requireField`,
`refuseTransition`, `runFlow`.

**Verdicts**: `fired`, `no_match`, `refused`, `error`.

## The endpoints

| method | path | what it answers |
| --- | --- | --- |
| GET | `/api/rules/vocabulary` | The three closed sets. Open to any signed-in caller. |
| GET | `/api/schemas/{schema}/rules` | The inventory, in evaluation order. Admin. |
| PATCH | `/api/schemas/{schema}/rules/{ruleId}` | The switch, with the audit entry in the response. Admin. |
| POST | `/api/schemas/{schema}/rules/{ruleId}/evaluate` | The dry run. Writes nothing. Admin. |
| POST | `/api/schemas/{schema}/rules/{ruleId}/replay` | Previews a replay over existing objects. Writes nothing. Admin. |
| GET | `/api/rules/{ruleId}/runs` | The run log, filtered by verdict and period. Admin. |

Everything but the vocabulary is an admin read. The inventory exposes the
conditions that decide who may move a case on, and the operand values that
decided a refusal. That is the configuration of the instance's own gates.

## What an inventory entry carries

`id`, `kind`, `schema`, `key`, `label`, `source`, `enabled`, `actions`,
`condition`, `maxObjects`, `order`, plus the summary merged on: last run, last
error and whether the rule ran inside the idle window.

The inventory is a projection. It stores nothing of its own and reads the four
places a rule actually lives, so a rule absent from the schema cannot appear in
it and a rule in the schema cannot be missing from it. The switch writes back
into the declaration that owns the rule, so a schema stays diffable, exportable
and importable with its rules in the state the administrator left them.

## The trace

`{verdict, operand, operandValue, message}`. On anything but `fired` the operand
is the first one that decided, with the value it read, cut at 120 characters
with a visible ellipsis. Render `operand` and `operandValue` beside the verdict:
they are what turns "waarom is de flow niet gelopen" into a fact.

## Writing a condition

Two dialects, both evaluated by the same class on the save path, on the dry run
and in the tracer.

- **JSON AST**, what an administrator authors: `{"gt": [{"prop": "object.bedrag"}, 500]}`.
  Its operator catalogue is `GET` on the calculation operator surface, and the
  same table the evaluator dispatches on.
- **JSONLogic**, legacy and still supported because schemas carry it today:
  `{">": [{"var": "object.bedrag"}, 500]}`.

Twig `computed` is the other legacy form, for a computed value rather than a
condition.

The document a condition reads has exactly four keys: `object`, `previous`,
`user` (`uid`, `groups`) and `transition` (`action`, `from`, `to`).

## The two property annotations

**`x-openregister-dependent-values`**, on the property whose values follow
another's:

```json
{
  "controlledBy": "zaaktype",
  "allowed": { "bezwaar": ["gegrond", "ongegrond"] }
}
```

A controlling value the table does not list constrains nothing, which is what
lets a table cover the three zaaktypen that need one. Schema save refuses a
table naming a property the schema does not declare, or a value the controlling
property cannot take. Object save refuses with 422 naming both properties.

**`x-openregister-default-expression`**, a JSON AST evaluated on create:

```json
{ "dateAdd": { "date": { "prop": "ontvangstdatum" }, "amount": 42, "unit": "days" } }
```

It refuses rather than writing an empty value. Every expression reads the
submitted object, not what another expression default produced, so the result
does not depend on declaration order.

## The ceiling and the replay

A rule may declare `maxObjects`. A replay counts the selection first and refuses
the whole run above the ceiling, naming the rule and the count, leaving no
partial mutation. Under the ceiling it creates a previewed `bulk-action-jobs`
job with a per-object outcome, which the operator commits or does not.

A rule that declares no `maxObjects` is bounded only by the instance-wide bulk
job ceiling, exactly as before.

## Cluster 19 candidates this closes

From `procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence:

C-tasks-and-phases-9, C-tasks-and-phases-17, C-tasks-and-phases-25,
C-configuration-14, C-configuration-17, C-configuration-25, C-configuration-29,
C-configuration-33, C-configuration-34, C-configuration-47, C-configuration-50,
C-configuration-58, C-configuration-65, C-configuration-69, C-configuration-106.

Ledger rows 11.29 and 11.30.

## What it does not close

- **C-tasks-and-phases-30**, rules suggested from the case data. The engine half
  rates `yes` already; the suggestion half is a hermiq question under D13.
- **C-configuration-40**, scripted form behaviour without a deployment. A
  scripting seam is a decision of its own, and the candidate note names it
  beside a supply-chain risk.
- A condition that reads a case-type property rather than a built-in field, and
  a rule action that makes a field required on a case type. D3 names both as
  still missing. They are the CT-2 extension of `field-rules-by-state`, and they
  are the dossiq lane's own work, not this change's.

## What dossiq should build on it

The case-type editor reads two endpoints and declares a ceiling. Per case type
it declares which fields are hidden, read only or required, which is the
`stateFieldRule` kind, and it reads the inventory and the run log into that
editor instead of building a second rule screen.

The one thing to avoid: do not key anything on a rule's position in the
inventory. The order is a property of the pipeline, not of a rule, and it will
change when a kind is added. Key on the id.
