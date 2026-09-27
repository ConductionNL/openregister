---
kind: code
---

# Proposal: modelling-field-access-editor

## Summary

A functional administrator decides, in the property editor, which groups may see a
field and which may change it. A salary field is hidden from everyone outside HR, a
decision date is locked for everyone but the team lead. The rule is the property-level
authorization Open Register already enforces; this change gives it a screen, and a
component buildiq can embed in its own field editor.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| buildiq | acc-field-level | Hide or lock individual fields for some roles | no |

Row acc-field-level is in buildiq's matrix, owned here because built.owner is
ConductionNL/openregister. No demand row. Competitors rated yes:

- nocobase (source read at v2.2.18, not driven): "packages/plugins/@nocobase/plugin-acl/src/client/permissions/RolesResourcesActions.tsx:63
  per action field lists (action.fields) decide which fields a role may view, create or
  edit; mounted in the role collection permission drawer of
  packages/plugins/@nocobase/plugin-acl/src/client-v2/plugin.tsx:21".
- budibase (source read at v3.46.0, not driven): "packages/builder/src/components/backend/DataTable/buttons/grid/ColumnsSettingContent.svelte:55-109
  sets each column of a view to writable, read only or hidden (FieldPermissions)".
- mendix (docs-only), https://docs.mendix.com/refguide/access-rules/: "entity access
  rules grant module roles read or read-write rights per member (attribute and
  association), so fields can be hidden or locked per role".
- power-apps (docs-only), https://learn.microsoft.com/en-us/power-platform/admin/field-level-security:
  "column-level security prevents users from setting or viewing a column, with
  optional masking".

## Why

Enforcement exists. `lib/Service/PropertyRbacHandler.php` reads a property's
`authorization` block (`{"read": [...], "update": [...]}`, documented in its header),
checks it in `canReadProperty()` (:100) and `canUpdateProperty()` (:122), and strips
unreadable fields in `filterReadableProperties()` (:150). `PropertyValidatorHandler`
accepts the key (`lib/Service/Schemas/PropertyValidatorHandler.php:513`).
`field-rules-by-state` adds hidden, read-only and required per role and state.

Nobody can author it without writing JSON by hand. `src/modals/schema/EditSchemaProperty.vue`
has no authorization section, and the buildiq evidence says its field editor has none
either: "Reachable only by writing a property authorization block into the schema by
hand".

## What changes

- The property editor gains a section "Who may see and change this field": a table of
  groups with a Read and a Change switch per group, plus `public` and
  `authenticated`, the same shape the schema-level `RbacTable.vue` uses for a whole
  schema.
- Leaving the table empty means the field follows the schema's rules, as today.
- The section warns when a field is required but some group that may create objects
  may not change it, because that group could never save.
- The section is a nextcloud-vue component (`CnPropertyAccessEditor`), so buildiq and
  other schema editors use the same one.
- The object list and detail views mark a field the current user may read but not
  change as read-only, from the rules the API already applies.

## Consumers

- buildiq embeds the component in `src/components/schema-editor/FieldEditor.vue`.
- humaniq, dossiq, learniq (row gov-hide-a-field-from-a-role) get a screen for a rule
  they now declare in JSON.

## ADRs

- hydra ADR-005 (security) and ADR-055 (authorization gate extensions): the server
  stays the only gate; the editor writes the declaration and the UI state is a hint.
- hydra ADR-017 and ADR-072: the editor is one nextcloud-vue component, not one per app.
- openregister ADR-010 (permission verb extensions): the verbs are `read` and `update`
  as the handler already uses them.

## Impact

- Extends `row-field-level-security`.
- Affected code: `src/modals/schema/EditSchemaProperty.vue`, nextcloud-vue
  `CnPropertyAccessEditor`, the object form's read-only marking, a small endpoint or
  render field telling the client which properties are read-only for this user.
- Backwards compatible: a property without `authorization` behaves as today.
- Size: M.

## Out of scope

- Masking a value (showing part of it). A later change can add a `mask` verb.
- Conditions on the object's data in the editor (`match` blocks). The table authors
  plain group rules; a property that already carries a `match` condition shows it
  read-only with a note that it is edited as JSON.
