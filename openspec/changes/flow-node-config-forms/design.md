# Design: flow-node-config-forms

## Why declaration-only, and where the two real decisions are

Eighteen of the nineteen implementations are mechanical: read `configKeys()`,
describe each key with the existing field vocabulary. The rendering path is
proven end to end by `AwaitSignalNode` — `configForm()` → palette →
`CnFlowSidebar` renders fields. Two places need actual decisions.

## Decision 1: keyed structures get PARTIAL forms, not new field types

`SwitchNode` and `RouterNode` carry branch tables — a keyed structure whose
keys are the author's own branch names. The field vocabulary (`text`,
`textarea`, `number`, `boolean`, `select`) cannot describe "a map of
expressions", and this change does NOT extend it.

Options considered:

1. **Add a `table`/`keyValue` field type.** Rejected here: it changes the
   editor contract in nextcloud-vue, every consuming app's bundle, and the
   interface's documented vocabulary — for two nodes. If contributed nodes
   later demonstrate the same need, that is its own change with its own
   fleet-wide review.
2. **Serialise the table into a `textarea`.** Rejected: a JSON blob inside a
   form field is the raw pane with fewer affordances and a second place to
   make parse errors.
3. **Partial form** (chosen): the scalar keys get fields; the branch table
   stays in the JSON pane, and the form's help text says so explicitly. The
   interface documents exactly this: "a partial form is more useful than
   none, and it lets a node describe the two fields worth guiding and leave
   the rest."

The round-trip requirement is what makes option 3 safe: a partial form that
dropped unnamed keys would destroy the branch table on every form edit. That
is why the round-trip invariant is spec'd in the same change as the forms
that depend on it, not left as editor folklore.

## Decision 2: the empty form is an explicit declaration, not an absence

`TriggerManualNode` accepts no configuration keys at all (spec: "it MUST
accept no configuration keys"). Today the editor cannot distinguish "this
node declared nothing" (fall back to raw JSON) from "this node declared it
needs nothing". Both render as a JSON pane over `{}` — the first correctly,
the second confusingly.

Implementing the interface with an empty array makes the second case
expressible: the palette entry carries `form: []`, and the editor renders a
"no configuration" state. The alternative — a separate marker interface — was
rejected as a second way to say something the existing return type already
says.

Consequence for the sweep test: the assertion is "carries a form
DECLARATION", i.e. the interface is implemented, not "carries at least one
field". An empty declaration passes; a missing one fails.

## What deliberately does not happen

- **No form registry, no central table.** Each node describes itself; the
  engine's only role is shipping the declaration on the palette entry it
  already builds. This is the architecture the spec requirement mandates for
  contributed nodes, applied uniformly to built-ins.
- **No validation moved into forms.** `validateConfig()` stays the authority;
  a form's `required` flag is a hint to the editor, not a second validator
  that could drift from the node's real refusal.
- **No obligation on openconnector/hermiq in this change.** The interface
  crossing repo boundaries is the point of its design; the obligation cannot
  cross in an openregister change. Follow-ups are filed in those repos.
