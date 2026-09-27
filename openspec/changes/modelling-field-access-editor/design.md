# Design: modelling-field-access-editor

Read at openregister development 0ca409ee04.

## D-1: the editor writes the block the handler already reads

`PropertyRbacHandler` documents the shape in its header:
`"authorization": {"read": [{"group": "..."}], "update": [{"group": "..."}]}`. The
editor writes exactly that, one entry per ticked group. It never writes a `match`
condition; an existing rule with one is shown read-only (see the proposal's out of
scope), so the editor cannot silently drop a condition an app declared.

## D-2: the table mirrors the schema-level table

`src/components/RbacTable.vue` renders groups by create, read, update and delete for
a whole schema, with `public` pinned on top. The property table has two columns, Read
and Change, and the same group list (`userGroups` is already loaded for the schema
dialog in `src/modals/schema/EditSchema.vue`). It is built as nextcloud-vue's
`CnPropertyAccessEditor` so buildiq's `FieldEditor.vue` and Open Register's
`EditSchemaProperty.vue` share it.

## D-3: the required-field warning

A property listed in the schema's `required` that a create-capable group may not
update is a save that can never succeed for that group. The editor computes it from
the schema authorization and the property rule and shows a warning; it does not
refuse, because an app may fill the field with a default or a calculation.

## D-4: read-only marking in forms

The API already refuses a write to a property the caller may not update:
`SaveObject` collects them with `getUnauthorizedProperties()` and throws a plain
`Exception` (`lib/Service/Object/SaveObject.php:3214-3224`). That becomes a typed
exception the controller answers as 403 naming the properties, so a client can tell a
forbidden field from a server fault. The client needs to know in advance. The object render
gains `@self.readOnlyProperties` for the current user, computed with the same handler,
so a form disables those inputs. It is a hint; the refusal stays on the server.

## Declarative-vs-imperative decision

Declarative: the rule is the property's `authorization` block, enforced by the
existing handler. The change is an editor for it.

## Risks

- A UI that looks like a security control but is not: D-4 keeps the server as the
  gate, and tests assert a write to a read-only field is still refused through the API.
- Performance of `@self.readOnlyProperties` on lists: computed once per schema per
  request for the caller, not per object, unless a rule carries a `match` condition.
