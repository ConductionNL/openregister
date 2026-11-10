# A transition input offers choices

## What was wrong

A transition input could only say WHICH field it asks for and whether it is
required: `inputs: [{field, required}]`. A client then had to work out from the
schema how to ask for it. That works for a reason or a date. It does not work
for a value whose choices depend on the record rather than on its schema.

The case that showed it: closing a case in dossiq asks for the result. The
results a case may close with belong to its case type, so no schema `enum` can
list them. The stages widget therefore rendered a bare required text field
labelled `resultTypeId *`, and a handler had to paste a UUID to close a case.

## The change

An input MAY carry two keys that say how to ask for the value:

- `label`: the words beside the field.
- `options`: `[{value, label}]`, the values to pick from.

`publishedInputs()` carries both through for static, graph and provider
actions alike, so a provider (which knows the record) can publish the choices
for THIS record. An option without a usable value is dropped, one without a
label is labelled by its value, and an input that declares neither keeps the
bare `{field, required}` shape, so no existing response changes.

Both keys are presentation only. The allowlist is unchanged, and the value is
validated by whoever writes it (the save path, or the provider), so the
published options never become a second authority on what is accepted.

## Consumers

`@conduction/nextcloud-vue`'s `CnTransitionInputDialog` renders an input with
`options` as a picker. An older client ignores both keys and shows the field
as before.
