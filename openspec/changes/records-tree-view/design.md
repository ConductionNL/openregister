# Design: records-tree-view

Read at openregister development 0ca409ee04.

## D-1: one declaration for access and for the tree

`HierarchyGrantExpander::declarationFor()` returns `{parent, maxDepth, verbs}` from the
schema's `x-openregister-hierarchy` block, accepting `parentField` as an older
spelling. The tree reads the same method, so a schema that inherits access by parent
shows as a tree with no second declaration. A tree view on a schema without the block
is refused at view save.

## D-2: level by level

The roots are the records whose parent property is empty. The list call uses the
existing filter path with an is-null condition on the parent property (the
`_isnull` filter operator of the open change `isnull-filter-operator`, which fixes the
advertised `?<field>_isnull=true`). A branch is the same list with an equality
filter on the parent uuid. Each request is paged like any list, so a node with 5,000
children pages rather than loading all of them.

## D-3: child counts in one query

`_childCount=true` on a schema with a hierarchy adds `@self.childCount` to each object
of the page. The count is one grouped query over the magic table: count by parent for
the page's uuids, honouring the caller's RBAC the same way the list does. The parent
column is indexed already when the property is a relation (`MagicMapper::createTableIndexes()`,
`lib/Db/MagicMapper.php:3552`), and `modelling-property-index-switch` covers a plain
parent property.

## D-4: orphans the viewer can see

If a record's parent is not readable by the viewer, the record would never appear
under any node. The root request therefore also returns readable records whose parent
is not readable, marked `@self.parentHidden: true`, and the tree shows them at the top
with a note.

## D-5: rendering

`presentationType()` in `src/views/search/SearchIndex.vue` returns `tree`, and the page
renders nextcloud-vue's `CnTreeView`, loading a branch when it opens. A node shows the
label field and the child count; choosing it opens the record detail.

## Declarative-vs-imperative decision

Declarative: the hierarchy is the existing `x-openregister-hierarchy` annotation, and
the view is a declared presentation.

## Risks

- Cycles in bad data (a record that is its own ancestor): the tree loads one level at
  a time and never walks, so a cycle shows as a node that repeats when opened, not as
  a hang. `HierarchyAnnotationValidator` already guards the declaration itself.
