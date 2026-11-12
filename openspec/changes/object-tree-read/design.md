# Design: object-tree-read

Read at openregister development b9e5d8df4.

## D-1: no second declaration

The parent property comes from `x-openregister-hierarchy.parent` when present, else from
`x-openregister-party.parentProperty`. Both already exist and both mean "this property
holds the uuid of the parent object in this schema". The hierarchy one also sends
grants down, which an organisation group must not do (a person allowed to read the
holding is not thereby allowed to read every subsidiary), so the party declaration is
how an organisation tree is declared. The depth bound is the declaration's `maxDepth`,
held under `HierarchyGrantExpander::DEPTH_CEILING`.

## D-2: one query per level

Parent and child are rows of the same magic table, so a level is
`HierarchyDescender::childrenOf()` (one chunked `IN` query). Names and requested fields
come from `MagicMapper::findObjectsByUuidsInRegisterSchema()`, one query per level.

## D-3: RBAC per node, pruning the subtree

Every level is filtered by `MagicMapper::filterReadableUuids()`, the same access filter
the single-object read applies. A node the caller may not read is left out and the walk
does not continue below it, so the answer never shows a level number that skips a
hidden organisation. Walking up stops at the first unreadable ancestor for the same
reason. In-process callers that scope their own reads (portaliq reads with
`_rbac: false` everywhere and applies the portal subject's scope itself) pass
`_rbac: false`. The API route always applies RBAC.

## D-4: bounds and an answer that says it is partial

Three bounds: the declared `maxDepth`, the requested `depth`, and the instance node cap
(`RelationGraphService::maximumNodes()`, setting `relationGraphMaxNodes`). The answer
carries `truncated` and `truncatedBy` (`depth`, `cap`, or `unreadable` when a level
could not be read). `depth` is only reported when there really is more below the last
level, so a consumer can refuse a partial tree, which is what portaliq does.

## D-5: cycles

A uuid already in the answer is never added again, in either direction, so a cycle in
the data ends the walk. Writes are already guarded by `PartyTreeGuardListener`; the read
does not trust that.

## Answer shape

```
{ root, register, schema, parentProperty, direction, depth, requestedDepth, maxDepth,
  nodes: [{ uuid, name, level, parent, properties: {<field>: value} }],
  truncated, truncatedBy }
```

Ancestors have negative levels (nearest is -1) and come first, the root is level 0,
descendants follow breadth first.
