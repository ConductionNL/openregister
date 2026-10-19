---
kind: code
---

# Proposal: deferred-listener-batches-stay-under-the-job-limit

## Summary

`ListenerDeferralService` enqueued every buffered chunk as one background job. Nextcloud refuses a job argument longer than its limit (4,000 characters on Nextcloud 32 and 33, 32,000 from 34), and an update notification entry carries the object's previous data (`oldData`), so a chunk of 25 updates of an ordinary case passed the limit. `IJobList::add()` threw, the service logged "Failed to enqueue deferred listener job" and every entry of the chunk was lost.

A chunk is now cut by size as well as by count, so its entries spread over as many jobs as the limit needs. An entry that alone exceeds the limit is enqueued without its `oldData`; only one that still exceeds it afterwards is left out, and the log names it.

## Why

Zuiddrecht live test, 14 October 2026: one cron run that swept hundreds of dossiq cases logged `ListenerDeferralService.php:277` "Background job arguments can't exceed 32000 characters" (32,000 is the limit from Nextcloud 34; 32 and 33 refuse at 4,000) eleven times, each for a chunk of 25 `AnnotationNotificationDispatchJob` entries. Those updates sent no notification.

## What changes

- `ListenerDeferralService::flushBuffer()` splits the buffer into consecutive chunks whose JSON argument stays within the running server's job argument limit (`ListenerDeferralService::getArgumentLimit()`: `JobList::MAX_ARGUMENT_JSON_LENGTH` where Nextcloud defines it, 32,000 from 34, else the 4,000 of Nextcloud 32 and 33), measured the way Nextcloud's job list measures it, and enqueues each.
- An entry larger than the limit is enqueued without its `oldData` (the entry is kept; conditioned `updated` rules and `calculatedChange` rules, which fail closed without the previous data, are skipped for that object) and the reduction is logged at warning level.
- An entry still too large after that is logged at error level with its job class and uuid, and the rest of its chunk still goes.

## Screens

None.
