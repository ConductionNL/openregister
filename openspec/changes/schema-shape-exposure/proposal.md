---
kind: code
---

## Why

openregister#3934 and #3938 established that an aggregate over a property is a
read of that property: a facet returns its distinct values, a sum over a salary
nobody may read IS the salary total, and a kanban column heading is a value.
Those paths now ask the read rule.

Both changes left one question open and named it rather than deciding it: the
OpenAPI description and the GraphQL type mapper describe a schema's SHAPE,
including properties the caller may not read. That is a different exposure,
because a shape is not a value, and it deserved a decision rather than a reflex.

**A field name can itself disclose.** `onderzoek_integriteit`,
`schuldhulpverlening`, `bijzondere_bijstand`, `hiv_status`: the name alone says
what category of fact is held, and on a record about one person it says the fact
is held about them. "It is only the shape" is safe for `postcode` and unsafe for
exactly the properties somebody bothered to govern. A property carries an
authorization block or a scope precisely because it is sensitive, so the set of
governed names is, by construction, the set most worth not printing.

## What Changes

**The decision: a property's existence follows its read rule.**

The usual objection is the contract. Clients generate code from the OpenAPI
document, and a document that varies by caller generates different clients. That
cost is real and it is smaller than it looks, because **a property the caller may
not read is never returned to them.** Describing it promises a field that will
never arrive. Omitting it makes the description MORE truthful, not less: it
describes the API this caller actually has.

- `OasService` describes only the properties the caller may read. `required`
  drops any name it can no longer mention, because a required list naming an
  absent property is not a contract anyone can satisfy.
- The GraphQL type mapper does the same, for the same reason.
- **The omission is disclosed as a COUNT, never as names.** Naming the withheld
  properties in the document would defeat the whole point. A count lets an
  integrator tell "this schema has nothing else" from "there is more here that is
  not yours", without saying what.
- `example` and `enum` go with the property they belong to. An example is a
  sample answer and an enum is the set of permitted answers; both are values.

**The three principals differ, and the difference is enforced:**

| principal | sees |
|---|---|
| anonymous | only properties readable without signing in |
| signed-in colleague | the properties their groups may read |
| administrator | every property, because they already bypass property-level reads everywhere else |

The administrator row is deliberate. Making the OpenAPI document the one place an
administrator cannot see the schema would be a second answer to a question
`PropertyRbacHandler` already answers, and two answers drift.

## Capabilities

### Modified Capabilities

- `rbac-scopes`: property-level read authorization is extended from values and
  aggregates to the DESCRIPTION of a property's existence.
