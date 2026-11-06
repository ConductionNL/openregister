# Tasks

## 1. Compile
- [x] 1.1 Map `interval` to an exact cron expression or refuse it
- [x] 1.2 Map an allow-listed action to its flow node, reading `arguments.synchronizationId`

## 2. Apply
- [x] 2.1 Build the flow of a schedule with `runAs` and owner = publisher and organisation = the application's
- [x] 2.2 Upsert, disable and remove compiled flows; a repeat apply changes nothing
- [x] 2.3 Publish hook on `productionVersion` and on the production version's `schedules`
- [x] 2.4 Refuse and log without a publisher, without an organisation, or for a non-member
- [x] 2.5 Audit entry when a schedule's identity changes
- [x] 2.6 End-to-end test from a manifest with a cron schedule to a queued run

## 3. Fire
- [x] 3.1 Disable a compiled flow whose `runAs` left the organisation

## 4. Clean up
- [x] 4.1 Report on-disk schedules as refused
- [x] 4.2 Delete `ScheduleReconciler` (#5806) and whatever else in `lib/AppHost/Scheduling/` stays without a caller
