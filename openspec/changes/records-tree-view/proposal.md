---
kind: code
---

# Proposal: records-tree-view

## Summary

A user browses records that form a hierarchy, such as departments, product groups or
categories, as a collapsible tree. They open a branch to load its children, see how
many children each node has, and open any node as a record. The tree follows the same
parent property a schema already declares for inherited access, so an administrator
declares the hierarchy once.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | rec-tree | Browse records that form a hierarchy, such as departments or product groups, as a collapsible tree | no |
| buildiq | data-tree-structure | Model hierarchical data such as categories as a tree and browse it in a tree view | partial |

**rec-tree** is in Open Register's own matrix, in its core area (records). Demand:
feature request, https://github.com/directus/directus/discussions/3054. No competitor is
rated yes on this row.

**data-tree-structure** is in buildiq's matrix, owned here because built.owner is
ConductionNL/openregister. No demand row. Competitors rated yes:

- nocobase (source read at v2.2.18, not driven): "packages/plugins/@nocobase/plugin-collection-tree/src/client-v2/plugin.tsx:92
  Tree collection template; tree filter block at
  packages/plugins/@nocobase/plugin-block-tree/src/client-v2/models/TreeBlockModel.tsx:581".
- mendix (docs-only), https://docs.mendix.com/appstore/modules/tree-node/: "the
  platform-supported Tree Node widget displays levels of tree nodes, used over a
  self-referencing association".
- power-apps (docs-only),
  https://learn.microsoft.com/en-us/power-apps/maker/data-platform/define-query-hierarchical-data:
  "a 1:N self-referential relationship set as hierarchical lets you query data as a
  hierarchy and create visualisations of it".

## Why

A schema can already say which property points at a record's parent:
`x-openregister-hierarchy` with `parent` and `maxDepth`, read by
`HierarchyGrantExpander::declarationFor()` (`lib/Service/Rbac/HierarchyGrantExpander.php`,
annotation constant at :78) and checked at save by
`lib/Service/Rbac/HierarchyAnnotationValidator.php`. Today only access inheritance reads
it. No list shows parent and child records as a tree: the matrix evidence says "grep
treeview/TreeView in src: no match", and the only hierarchy view is for code list
concepts in the property editor (`src/modals/schema/EditSchemaProperty.vue:718-745`).
buildiq's evidence: "no built page shows a tree: nextcloud-vue CnIndexPage renders a
flat table".

nextcloud-vue's development branch ships `CnTreeView` (with a recursive `CnTreeNode`),
so the component exists and needs data.

## What changes

- A saved view accepts `viewType` `tree`, allowed only on a schema that declares
  `x-openregister-hierarchy`. Its config names the label field and optional extra
  fields per node.
- Opening a tree view lists the root records (those with no parent) with a child
  count each. Opening a node loads its children, one level at a time, with the view's
  filters applied.
- A child whose parent the viewer may not read appears at the top level, marked, so
  nothing the viewer may read disappears.
- The object list API returns `@self.childCount` for a schema with a hierarchy when
  asked with `_childCount=true`, counted in one grouped query per page.
- The same tree is available to leaf apps: nextcloud-vue's index page can render a
  tree presentation for such a schema, which is what buildiq's pages need.

## Consumers

- buildiq pages over a self-referencing schema.
- humaniq (departments), shillinq (product groups), opencatalogi (themes), keepiq
  (asset hierarchies).

## ADRs

- hydra ADR-031: the hierarchy is declared once on the schema and read by both access
  inheritance and the tree.
- hydra ADR-058 and openregister ADR-009: one level per request, and child counts in
  one grouped query per page, never a walk per node.
- hydra ADR-059: the tree is keyboard operable (arrow keys open and close branches).

## Impact

- Extends `saved-search-views`.
- Affected code: view presentation validation, the objects list (`_childCount`),
  `src/views/search/SearchIndex.vue`, nextcloud-vue `CnTreeView` wiring in the index page.
- Backwards compatible: a schema without a hierarchy is unchanged.
- Size: M.

## Out of scope

- Dragging a node to a new parent. Moving a record is an edit of its parent property.
- Trees across schemas (a category tree whose leaves are products of another schema).
