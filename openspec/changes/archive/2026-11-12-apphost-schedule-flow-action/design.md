# Design: manifest schedules run as scheduled flows

## Context

Scheduled flows already work: `FlowScheduleWorker` ticks, `FlowScheduleService` finds due flows through `FlowLocator`, and `FlowRunService::queue()` refuses a flow with no published version or no identity. What is missing is the step from a manifest to such a flow.

## Decisions

### D-1: The publish hook is the apply trigger

There is no apply event, and buildiq sets `productionVersion` on an ordinary object, so a listener on the object events is the hook. `ApplicationPublishListener` applies a manifest when, in the `buildiq` register (whichever slug the instance carries):

- an `application` object's `productionVersion` changes, or
- the `schedules` of the `applicationVersion` that is currently production change. buildiq's automation compiler writes straight onto that version, so this is also a publish.

A production version that cannot be read, or that belongs to another application, is refused and removes nothing. Any other save does nothing, which is what keeps re-saving an application from changing an identity. A listener sees the session of the person who saved, which is the identity D-3 needs. A sweep would not. Deleting the application, or clearing its production version, removes its compiled flows.

### D-2: One flow per schedule, keyed by application and schedule id

The flow's uuid is derived from the application's object uuid and the schedule `id`, so the same schedule always lands on the same row. The flow's `applicationSlug` column holds that uuid too: a slug is a data property two organisations may share, and keying on it let one tenant take over or delete another's flow. It also means a renamed application keeps its flows. The `app` column holds `apphost-schedule`, which marks the flow as compiled; a flow an author drew for the same application is never touched.

Applying compares each desired flow with the stored one:

| stored | manifest | result |
|---|---|---|
| none | schedule | insert, validate, publish version 1 |
| same graph, cron, enabled, owner, organisation | schedule | nothing |
| different | schedule | draft, write, validate, publish the next version |
| flow | schedule gone | delete with its runs and versions (`FlowService::deleteStored`) |

`enabled: false` keeps the flow and switches it off. A schedule refused for its interval or action is logged and leaves any earlier flow for that id alone: the manifest does still name it. An entry that does not parse has no reliable id, so it counts as gone.

Because `runAs` is in the graph, a publish by someone else writes a new flow version, and that version names them in `publishedBy`.

### D-3: A run acts as the publisher

`runAs` on the trigger node is the session user at publish time. Acting as yourself needs no grant (ADR-099 section 4), so `FlowTriggerValidator` stamps nothing, and the fire path re-resolves the identity like any other schedule. `flow.owner` is the publisher too, since someone has to be able to edit and be told. The manifest still cannot name an identity: a `runAs` or `owner` key on a schedule is ignored.

No session means no publisher. That save refuses, and earlier flows stay as they were. Every refusal is logged and also named in the response of the request that caused it, in the header `X-OpenRegister-Schedule-Refused` (a JSON array of reasons), so a publish that succeeded with a refused schedule says so. The middleware is global because a publish can arrive through any app's controller, buildiq's promote among them. A write in that state would count as code-initiated in `FlowTriggerValidator`, which skips the delegation check, so it must not happen.

Publishing again as another user changes the identity of every schedule of that app. Each change writes an entry with action `schedule.run_as_changed` on the application's audit trail: the schedule, the previous and new user, and when. The entry is written before the flow: a failed audit changes no identity, and a failed write leaves an entry that the next publish repeats.

### D-4: Tenancy comes from the application

`flow.organisation` is the application's `@self.organisation`, not the publisher's active organisation. `FlowService::save()` stamps the second, so this path writes through the mapper, with the same trigger validator and version service around it.

The publisher must be a member of that organisation (`Organisation::hasUser`). An administrator is not exempted: administrator access to an organisation is a right to manage it, not membership, and a schedule runs with its identity's rights in that tenant. No organisation, or no membership: the save refuses and logs.

Membership can end after publishing, so `FlowRunAttribution` asks again each time a compiled flow fires. A `runAs` that left the organisation counts as no identity: the queue refuses it, and the flow is disabled with the reason in `status_message`, as for a flow that names nobody, until someone publishes again. This check covers compiled flows only; hand-drawn flows keep today's rule.

### D-5: On-disk apps

A manifest in an app's `src/manifest.json` has no publisher. Its schedules are refused, and repair step `ReportUnpublishedSchedules` logs each one when the app is upgraded. An on-disk app that also has a published `application` object is applied through D-1 like any other.

### D-6: Interval to cron

Flows run on cron only, so `interval` is mapped, exactly or not at all:

| seconds | cron |
|---|---|
| divides an hour, whole minutes | `*/n * * * *` |
| divides a day, whole hours | `0 */n * * *` |
| 86400 | `0 0 * * *` |
| 604800 | `0 0 * * 0` |
| anything else | refused and logged |

A loose mapping would change how often work runs without anyone asking. A cron schedule covers every other case. The worker ticks every five minutes, so anything finer fires on the next tick, as for any scheduled flow.

### D-7: Actions stay a closed map

`ScheduleActionNodes` maps an action to a flow node; `ScheduleActionAllowList`, which mapped to a job class, goes with the reconciler. `openconnector:synchronization` becomes `openconnector.synchronization-run` with `config.synchronization` = `arguments.synchronizationId`, the name buildiq writes. The spec's older `arguments.synchronization` is aligned to it. The compiled graph is trigger, step, `openregister.end`.

## Not in this change

- An `openregister:flow-run` action. buildiq's automation does not need it, and it would add a cross-tenant question about which flows a schedule may name.
- A webhook action, as before.

## Seed data

None. Compiled flows exist per application at runtime.
