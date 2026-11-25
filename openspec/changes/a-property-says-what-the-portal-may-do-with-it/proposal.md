---
kind: code
depends_on: []
---

# Proposal: a-property-says-what-the-portal-may-do-with-it

Asked by buildiq `competitor-parity-2026-09` task 2.2 (dossiq carries the
other half as its competitor-parity task 15.7).

## Why

Decision D16 (Ruben, round 4): "The field carries whether the citizen may see
and change it, because that is a property of the field. The form carries which
fields appear in which order for which channel, because that is a property of
the form." Buildiq's registration forms now carry the order and the channel.
Nothing carries the field half: a portal that renders a case or a form has no
declared answer to "may the resident see this value, and may they change it",
so each consumer guesses, and a public form built from a schema with internal
fields can only be made safe by leaving those fields off by hand.

## What changes

- A property may declare `portal`: `"none"`, `"read"` or `"edit"`.
  Absent means `"none"` for a read or write that carries a portal subject, and
  changes nothing for any other caller.
- `PropertyValidatorHandler` knows the key and refuses another value.
- A read on behalf of a portal subject leaves out `none` properties; a write
  on behalf of a portal subject refuses a change to a property that is not
  `edit`, naming the property.

## Impact

- Affected specs: `schema-property-exploration` (the declared key).
- Affected code: `PropertyValidatorHandler` (known keys), the object read and
  write paths that already know the portal subject (`X-Portal-Subject`).
- Consumers: portaliq (renders only what it gets), dossiq (sets the flag in
  its case-type field editor), buildiq (reads it to warn when a public form
  lists a `none` field).
