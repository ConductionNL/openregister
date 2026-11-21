---
kind: code
---

## Why

An app declares recurring work in `manifest.schedules[]`, and buildiq's "Cron schedule" automation compiles to it. Nothing runs those entries. The `ScheduleReconcilerJob` was removed when OpenRegister moved to one flow engine (#3382), and the class it drove has no caller. This change replaced an earlier plan that added a job action on top of that reconciler: the reconciler stays gone, and a schedule becomes a scheduled flow.

The earlier plan also took the acting user from the application's owner. ADR-099 removed that reading for flows: owning a definition is not a mandate to act as its owner, and a schedule trigger must declare its `runAs`. Ruben decided (6 Nov 2026) that the user who publishes the production version is that identity.

## What Changes

- Publishing a production version applies the manifest's `schedules[]`: each schedule becomes or updates one scheduled flow, removed schedules lose their flow, and applying the same manifest again changes nothing.
- A compiled flow's trigger node declares `runAs` = the publisher. Publishing again as someone else changes it, and the audit trail on the application records who and when.
- A compiled flow's organisation is the application's `@self.organisation`. No run may act as a user outside that organisation: this is checked at publish and again before each firing.
- An app with no publisher or no organisation gets its schedules refused and logged, not run. That covers every on-disk app that has no published application object.
- `interval` maps to a cron expression where an exact one exists, and is refused where it does not.
- `arguments.synchronizationId`, what buildiq emits, is the argument of `openconnector:synchronization`.
- What is left of `lib/AppHost/Scheduling/` without a runtime caller is reused here or deleted.

## Capabilities

### Modified Capabilities
- `apphost-scheduling`: a manifest schedule runs as a scheduled flow, with its identity and tenancy defined.

## Impact

- `lib/AppHost/Scheduling/`, `lib/AppHost/Listener/`, `lib/Service/Flow/FlowRunAttribution.php`.
- No migration: compiled flows are ordinary rows in `openregister_flows`, marked by their `app` column.
- buildiq: no change. Schedules published before this change start running when the app is next published, because no publisher is known for them.
