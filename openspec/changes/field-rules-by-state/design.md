# Design: field-rules-by-state

## D-1: on the lifecycle, not on the property

The row's axis is the state. The lifecycle annotation is the one place that
already names states, so the rules hang under each state and the validator
can check every field and state exists. Putting a state condition on each
property's `authorization` block would scatter one decision over fifty
properties.

## D-2: required is evaluated on the state the object ends in

A save in state `open` checks `open`'s required list; a transition to
`closed` checks `closed`'s. A field required in `closed` need not be filled
while the object is `open`, which is the whole point of a per-state rule.

## D-3: hidden and readOnly reuse property RBAC

`PropertyRbacHandler::filterReadableProperties()` and
`getUnauthorizedProperties()` gain the state as an input and merge the
state rules with the property blocks. One stripping path, one refusal
path, so GraphQL and export inherit it.

## D-4: the form is told, not left to guess

`@self.fieldRules` is computed in `RenderObject` for the current user and
state, cached per (schema, state, groups) per request (openregister
ADR-009). A form that ignores it is still refused on save.

## D-5: kind

Code, in OpenRegister. Consuming apps declare.
