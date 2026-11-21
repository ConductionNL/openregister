# Proposal: or-flow-expressions

## Summary

Give flows an expression engine, and the first three nodes that need one.

## Why

Expressions are how one step reads another's output — what a condition
branches on and what a field is set from. Without them a flow can only move
data, never decide anything about it.

## Decision: JSONLogic, and the gap that leaves

`jwadhams/json-logic-php`, which openconnector already uses for
synchronisation and endpoint conditions. One expression language for the
fleet, not two. The dependency moves to OpenRegister so the engine does not
depend on a leaf app.

n8n uses a full JavaScript engine. Matching that means running user-authored
code inside the Nextcloud process — full `OC\Server`, database and filesystem
access from a text field in a flow editor. That trade is refused.

So JSONLogic is a ceiling **by decision**, and it genuinely cannot express
loops with state, parsing, or crypto. The route to those is the optional
sandboxed sidecar (#2066), never a relaxation here.

## What Changes

- `FlowExpression` — evaluation with a flow-shaped data document: `json`,
  `binary`, `itemIndex`, `itemCount`, `context`, `subject`.
- Custom operators for what flows need and JSONLogic lacks: string casing,
  trim, split/join, replace, regex match; date formatting and arithmetic;
  array unique/sort/length; `coalesce`, `toJson`, `fromJson`. Deliberately
  small — each exists because its absence would push an author toward a Code
  node.
- `FilterNode` — keeps the items whose condition holds. The simplest proof
  that an item list is a list.
- `WaitNode` — the node run persistence was built for. Suspends on the way in,
  passes through on the way back.

## Design decisions

- **An unevaluable condition is FALSE, not true.** A branch whose condition
  could not be evaluated must not be taken.
- **Evaluation returns null rather than throwing.** An author's typo should
  fail their condition, not abort a run mid-graph with side effects half
  applied. The failure belongs at save time — `isValid()`, called from
  `validateConfig()`.
- **A filter re-pairs survivors to their ORIGINAL index**, so provenance
  survives the drop.
- **Wait runs twice.** The marking does not advance past a suspended step, so
  the node sees `context.resuming` on the way back and lets items through. An
  unreadable time at run time passes through rather than suspending forever.

## Out of scope

- The expression editor UI with data in scope.
- `If`/`Switch` multi-branch routing, which needs labelled outputs on an edge
  (#2067).
