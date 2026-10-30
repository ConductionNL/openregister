# Tasks: deferred-listener-batches-stay-under-the-job-limit

- [x] 1. `ListenerDeferralService::flushBuffer()` cuts chunks by size as well as count (spec: event-driven-architecture, "A deferred listener chunk fits the job argument limit")
- [x] 2. Test through `AnnotationNotificationListener` with a job list that enforces the limit
