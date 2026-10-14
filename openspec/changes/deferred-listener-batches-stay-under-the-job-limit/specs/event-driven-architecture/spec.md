# event-driven-architecture

## ADDED Requirements

### Requirement: A deferred listener chunk fits the job argument limit

The listener deferral service SHALL enqueue a buffered chunk as one or more background jobs whose JSON-encoded argument each stays within Nextcloud's limit of 32,000 characters, keeping every entry once and in order. An entry that alone exceeds the limit SHALL be left out and logged with its job class and uuid; the other entries of its chunk SHALL still be enqueued.

#### Scenario: Twenty-five updates of large cases
- GIVEN a schema that declares update notifications and 25 updates of cases of about 2,000 characters each in one request
- WHEN the buffer is flushed
- THEN every update reaches the job list once, in order, spread over more than one job, and no job argument is longer than 32,000 characters
- @e2e exclude pinned through `AnnotationNotificationListener` by `DeferredChunksFitTheJobLimitTest::testAChunkOfLargeUpdatesIsSpreadOverJobsTheListTakes`

#### Scenario: One entry too large for any job
- GIVEN three updates of which the middle one is larger than a job may carry
- WHEN the buffer is flushed
- THEN the other two are enqueued and the log names the uuid of the one left out
- @e2e exclude pinned by `DeferredChunksFitTheJobLimitTest::testAnEntryTooLargeForAnyJobIsNamedAndTheRestGo`
