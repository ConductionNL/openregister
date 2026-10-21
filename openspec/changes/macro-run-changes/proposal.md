# Proposal: macro-run-changes

## Why

A macro run writes one `action.macro` audit entry naming the pressed action, the flow and the run. A reader cannot tell
from it what the run did: dossiq's board DqZaakAssistent draws "Laatst uitgevoerd: Opschorten voor aanvulling, 6 okt.
2 wijzigingen: termijn opgeschort, bericht verstuurd", and the only way to get there was to open the run.

## What changes

The entry's context gains `changes`: one `{ step, kind }` per completed step that wrote an object (`write`), sent a
message (`message`, email, notification or Talk) or created a task (`task`, user or portal task), in run order, named by
the node's `label` when the flow gives one (written for people), else by the node's id. A step of another app's node
type counts, as `step`, when its author labelled it: OpenRegister cannot tell what an app's node does. Steps that read, route or reshape items, and steps that did not complete, are left out.

## Scope

Additive to the audit entry's context. Existing readers ignore the new key.
