---
kind: code
---

## Why

The same declaration, `"update": []`, was read two ways:

- `MagicRbacHandler::hasPermission()` returns **false** — denied;
- `PropertyRbacHandler::checkPropertyAccess()` returns **true**, under a comment
  reading *"If action is not configured, property is accessible."*

Reported as an inconsistency with one side failing open. Measured, it is not.

## The decision: they are different kinds of declaration, and both are right

A **schema cascade is the last word.** Nothing runs after it, so an empty list can
only mean denied, and that is what it means.

A **property block is a narrowing on top of the object cascade.** An action it
does not name has no opinion at that layer, and the object's own rules still have
to pass. `getUnauthorizedProperties()` only consults properties that carry a
block, and a property it does not refuse is still written through the ordinary
object permission check.

So the property side is **not** a fail-open to "anyone". It is "no extra
restriction here". The word `accessible` in that comment was wrong and is what
made it read as a leak; it now says what it does.

## What we checked before deciding, and what harmonising would cost

Across the installed fleet on 2026-09-18: **8 property-level authorization
blocks, and all 8 are partial.** Not one names all four actions.

| app | property | names |
|---|---|---|
| decidiq | `BoardEvaluation.lifecycle` | `update` |
| stackiq | `contactPerson.roles` | `update` |
| stackiq | `organization.contactpersonen` | `read` |
| stackiq | `usage.interneAnnotation` | `read`, `update` |

Making the property side fail-closed would not tighten a leak. It would make
**every action those blocks do not name unwritable**, breaking all eight
declarations in two apps. A guard that can only be satisfied by breaking what it
guards is worse than no guard.

## What is genuinely sharp, and is left as a decision for schema authors

A property that restricts `read` and says nothing about `update` can be
**written** by anyone who may write the object — including somebody who may not
read it. `stackiq organization.contactpersonen` is that shape today.

That is a blind write, not a disclosure, and which of the two a schema wants is a
judgement about that schema. It is named in the class and here rather than
guessed at by the platform.

## What Changes

- The comment and the reasoning go into `PropertyRbacHandler`, where the next
  reader meets it, replacing the word that made it read as a fail-open.
- A test pins the distinction, so the two layers are not "harmonised" into a
  change that breaks eight declarations.

## Capabilities

### Modified Capabilities

- `rbac-scopes`: what an empty rule list means is stated for each layer.
