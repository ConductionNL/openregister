---
kind: code
---

# Proposal: shared-decision-table-evaluator

## Summary

Give the fleet one place to evaluate a DMN decision table. OpenRegister gets the
evaluator; the two apps that each built their own become consumers.

## Motivation

ADR-065 Decision 6 states this outright, and states why it is not a matter of
taste:

> the fleet has already built it twice, independently, and neither
> implementation knows the other exists

| App | File | Lines | Hit policies | Built |
|---|---|---|---|---|
| openbuild | `DecisionTableEvaluator` | 422 | any, collect, first, **priority**, unique | 2026-06-05 |
| dossiq | `Service/Dmn/*` | ~1,070 | collect, first, unique | 2026-07-15 |

Six weeks apart, and **the newer one is the less capable**. dossiq's archived
change references OpenRegister only as object storage; it never mentions
openbuild's evaluator, which had been shipping for six weeks.

The ADR calls that "the ADR's own thesis happening in real time", and it is the
single strongest argument for OpenRegister owning the abstraction: without a
shared home, the third implementation is a matter of time.

Measured again today, before writing anything: OpenRegister still has no
decision-table service, so nothing has changed since the ADR was written.

## Scope

### In Scope

1. **`UnaryTestEvaluator`** — dossiq's grammar, MOVED rather than rewritten.
2. **`DecisionTableEvaluator`** — the table engine, taking the UNION of the two
   dialects' hit policies.
3. **`DecisionEvaluationException`** — the typed error codes, moved with it.

### Out of Scope

- **Retiring either app's copy.** Each consumes this in its own change, with
  parity tests against its own shipped tables. Deleting a working evaluator on
  the strength of a new one that has not yet run its data would be the same
  mistake in the other direction.
- **A conformant FEEL implementation.** ADR-065 rules it out: unary tests cover
  the overwhelming majority of real tables and full FEEL is not worth the cost.
- **DMN XML interchange.** No package supplies it; the ADR records that as ours
  to write later, and it does not block the engine.

## What "reconcile the dialects" actually meant

The ADR warned this "must reconcile two dialects rather than simply relocate
one". Three places where that mattered:

**The grammar is dossiq's, moved verbatim.** It is the stronger of the two —
typed coercion (string / number / boolean / date), inclusive and exclusive
ranges, set membership, and a quoted-literal escape so a rule can match a
literal `"-"` rather than having it read as the wildcard. Retyping it would have
been a third implementation.

**`PRIORITY` is openbuild's, and its absence would have been a regression.**
dossiq's engine did not implement it; openbuild's tables can already use it.
Consolidating on dossiq's set alone would have silently broken them.

**`ANY` meant different things, and neither was DMN's.** openbuild treated it as
`collect` and returned a LIST — a different answer of a different shape. DMN says
a table declaring ANY asserts that its overlapping rules produce the same output,
so a disagreement is a fault in the table. It is implemented that way here, and
the test that pins it fails if it degrades to `first`.

## Risks

- 🔴 **A consumer's shipped tables may rely on the old ANY.** openbuild's tables
  declaring `any` got a list and now get a single value or an error. That is why
  retiring the app-side copies is out of scope: each app adopts this with parity
  tests over its own data, not on the strength of this PR.
- ⚠️ **`UNIQUE` throws on no match.** Inherited from dossiq deliberately: a
  decision table that matched nothing has not decided anything, and returning an
  empty output invites the caller to treat it as a decision.
