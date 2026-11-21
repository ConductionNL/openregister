---
kind: code
depends_on: [object-actions-declared-and-run]
---

# Proposal: declared-self-assign-action

## Summary

A schema can declare an action that sets one empty property to the calling user, for example taking an unassigned
case. The schema grants it by name, so a group with only read access may run it. `@self.can` lists every declared
action the caller may run on an object, next to `update`.

## Why

Ruben, 3 Nov 2026: a member of the group a case is assigned to must be able to take it without holding `update` on
the case. Today that takes an app controller per schema, or an `update` grant that also lets the member change every
other field.

## What changes

- A new action body, `"selfAssign": {"field": "<property>"}`. It accepts no inputs, no `elevate` and no async mode.
- Running it writes only that property, with the uid from the session, and only while the property is empty.
- It runs only when the schema grants it by name: an authorization rule or a matrix row. Without either nobody may run
  it, also on a schema whose empty block defaults open.
- Matrix rows (declared and app-provided) may grant actions the schema declares.
- `_extend[]=@self.can` returns `update` and one boolean per runnable declared action, for the caller of the request.

## Out of scope

Releasing an assignment, assigning somebody else, and procedure-specific behaviour. Those stay ordinary `update` or
other declared actions.
