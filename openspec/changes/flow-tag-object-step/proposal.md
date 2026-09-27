---
kind: code
---

# Proposal: flow-tag-object-step

## Summary

A sales manager builds a rule that labels a lead by itself: a trigger on lead
created or updated, a filter such as "value above 10,000", and a new step that
puts the tag "Large deal" on the lead. The same step can take a tag off, so a
lead that drops below the line loses the label. The tag can carry a colour,
which Nextcloud's system tags support from version 31, so the label reads at a
glance wherever tags are shown. The step acts as the flow's run identity and
only on records that identity may change.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| pipelinq | pipeline-auto-label | Have a coloured label put on a lead by itself when it matches a rule you set | partial |

Row `pipeline-auto-label` in pipelinq's matrix, owned here because
`built.owner` is ConductionNL/openregister: pipelinq's Flows pages run on Open
Register's flow engine, and object tags are Open Register's.

Demand rows:

- changelog, https://developers.hubspot.com/changelog/fall-2026-spotlight

Competitor yes cells, quoted from the packet:

- HubSpot CRM: "\"Object tags are colored labels automatically applied to
  records that match criteria you define, for example a 'Large deal' tag on any
  deal over $10,000\"; \"Available for Sales Hub and Service Hub Starter and
  up\"." Evidence: https://developers.hubspot.com/changelog/fall-2026-spotlight
- Odoo CRM: "addons/base_automation/models/base_automation.py:175-196
  automation rules on create or update with a domain filter set tag_ids on a
  lead without code (Settings > Technical > Automation Rules), for example a
  tag when expected revenue passes a threshold; tags carry a colour on the
  kanban card." Source path as cited, no URL.

## Why

Tags and flows both exist; no step connects them:

- Objects carry Nextcloud system tags through `TaggingHandler`
  (`lib/Service/File/TaggingHandler.php:307-350`, `addObjectTag()` and
  `removeObjectTag()`), exposed as `tags#add` and `tags#remove`
  (`appinfo/routes.php:1780-1781`, `lib/Controller/TagsController.php:191-260`).
- A flow can already START on a tag being assigned or removed
  (`lib/Listener/NativeFlowTriggerListener.php:140-144`, `tag.assigned` and
  `tag.unassigned`), but none of the nodes in `lib/Service/Flow/Nodes/` assigns
  or removes one. pipelinq's matrix: "a rule can set priority or another field,
  not a coloured label".
- `TaggingHandler::findOrCreateTag()` creates a tag by name only
  (`TaggingHandler.php:169-196`). Nextcloud added a tag colour in 31
  (`OCP\SystemTag\ISystemTag::getColor()`, `ISystemTagManager::updateTag(...,
  ?string $color, ...)`), and Open Register requires 32 (`appinfo/info.xml:129`),
  so the colour is available and unused.

## What changes

- A new node `openregister.tag-object` with `operation` (`add` or `remove`),
  `tag` (a name, templatable from the item), an optional `color`, an optional
  `uuid` template for the target (default: the item's own `uuid`), and
  `createIfMissing`.
- It acts as the run identity: each target must be an object that identity
  may update, or the step fails naming the object.
- Adding a tag an object already has, or removing one it does not have, does
  nothing and emits no tag event, so a flow triggered on `tag.assigned` cannot
  loop on its own step.
- A colour given on the step is set on the tag when the tag is created, and on
  an existing tag only when it has none.
- Items pass through unchanged.

## Consumers

- pipelinq (pipeline-auto-label): its Flows pages offer the step through the
  shared palette; a "Large deal" rule is pipelinq configuration.
- dossiq, planninq and decidiq can label cases, tasks and proposals the same way.

## ADRs

- hydra ADR-065: one flow engine; this is one more built-in node registered
  like the others.
- hydra ADR-005 (security): the step checks `update` on each object as the run
  identity and fails closed.
- hydra ADR-099: the step acts as the run's identity, never as the system.
- hydra ADR-031: see the declarative-vs-imperative decision.

## Impact

- Extends `flow-engine`.
- Affected code: a new `lib/Service/Flow/Nodes/TagObjectNode.php`,
  `lib/Listener/FlowNodeRegistrationListener.php` (registration),
  `lib/Service/File/TaggingHandler.php` (colour on create, an "already has"
  check), the node config form metadata.
- Backwards compatible: a new node.
- Size: S.

## Out of scope

- A tag colour editor in Open Register's own tag screens.
- Tagging files; the node tags objects.
- The authorization of the existing HTTP `tags#add` route, which checks that the
  caller can load the object (`TagsController.php:197-204`) rather than that
  they may update it. That is worth its own look and is not changed here.
