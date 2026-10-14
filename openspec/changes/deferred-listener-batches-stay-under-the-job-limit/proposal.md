---
kind: code
---

# Proposal: deferred-listener-batches-stay-under-the-job-limit

## Summary

`ListenerDeferralService` enqueued every buffered chunk as one background job. Nextcloud refuses a job argument longer than 32,000 characters, and an update notification entry carries the object's previous data (`oldData`), so a chunk of 25 updates of an ordinary case passed the limit. `IJobList::add()` threw, the service logged "Failed to enqueue deferred listener job" and every entry of the chunk was lost.

A chunk is now cut by size as well as by count, so its entries spread over as many jobs as the limit needs. Only an entry that alone exceeds the limit is left out, and the log names it.

## Why

Zuiddrecht live test, 14 October 2026: one cron run that swept hundreds of dossiq cases logged `ListenerDeferralService.php:277` "Background job arguments can't exceed 32000 characters" eleven times, each for a chunk of 25 `AnnotationNotificationDispatchJob` entries. Those updates sent no notification.

## What changes

- `ListenerDeferralService::flushBuffer()` splits the buffer into consecutive chunks whose JSON argument stays within `MAX_ARGUMENT_LENGTH` (32,000), measured the way Nextcloud's job list measures it, and enqueues each.
- An entry larger than any job may carry is logged at error level with its job class and uuid, and the rest of its chunk still goes.

## Screens

None.
