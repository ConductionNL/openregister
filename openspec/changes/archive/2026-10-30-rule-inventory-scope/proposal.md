---
kind: code
---

## Why

The rule inventory (`GET /api/schemas/{schema}/rules`) lists every rule of a schema. An app that keeps several kinds
of object in one schema, and writes rules for one kind only, cannot ask for that kind's rules. Dossiq is the first:
every case type writes its own conditional rules onto the one case schema, and the Rules tab of a case type must show
that type's rules plus the schema-wide ones, never another type's.

## What changes

- A rule declaration may carry `scope: { property, value }`: the rule is about the objects whose `property` holds
  `value`. It is read from:
  - a calculation, a state field block or a lifecycle transition, as a `scope` key beside the declaration;
  - a flow, from the `scope` key of the config of its object trigger node in the published graph.
- The descriptor returns `scope` (or `null`).
- `GET /api/schemas/{schema}/rules?scope[<property>]=<value>` returns the rules with that scope plus the rules without
  one. A rule scoped to another value, or to another property, is left out. Without the parameter nothing changes.

`scope` describes which objects a rule is about. It does not enforce anything: a rule keeps its own condition, and a
rule that should only act on one kind still says so in that condition.

## Not in scope

- Several scopes on one rule, or a scope with a list of values.
- Filtering the run log or the trial by scope.
