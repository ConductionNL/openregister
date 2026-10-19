# event-driven-architecture

## ADDED Requirements

### Requirement: A deferred listener chunk fits the job argument limit

The listener deferral service SHALL enqueue a buffered chunk as one or more background jobs whose JSON-encoded argument each stays within the running Nextcloud's job argument limit (4,000 characters on Nextcloud 32 and 33, 32,000 from 34; read from the server when it names the limit, 4,000 otherwise), keeping every entry once and in order. An entry that alone exceeds the limit SHALL be enqueued without its `oldData` and the reduction logged, naming that conditioned `updated` rules and `calculatedChange` rules are skipped for that object (they fail closed without the previous data); an entry that still exceeds it SHALL be left out and logged with its job class and uuid. The other entries of its chunk SHALL still be enqueued.

#### Scenario: Twenty-five updates of large cases
- GIVEN a schema that declares update notifications and 25 updates of cases of about 2,000 characters each in one request
- WHEN the buffer is flushed
- THEN every update reaches the job list once, in order, spread over more than one job, and no job argument is longer than the active limit (checked at 4,000 and at 32,000)
- @e2e exclude pinned through `AnnotationNotificationListener` by `DeferredChunksFitTheJobLimitTest::testAChunkOfLargeUpdatesIsSpreadOverJobsTheListTakes`

#### Scenario: An entry too large for the active limit is kept without its previous data
- GIVEN an update whose previous data alone makes its entry longer than the active limit
- WHEN the buffer is flushed
- THEN the entry is enqueued without `oldData`, and the log names its uuid and says that conditioned `updated` rules and `calculatedChange` rules are skipped for this object
- @e2e exclude pinned by `DeferredChunksFitTheJobLimitTest::testAnOversizedUpdateIsKeptWithoutItsOldData`

#### Scenario: One entry too large for any job
- GIVEN three updates of which the middle one is larger than a job may carry
- WHEN the buffer is flushed
- THEN the other two are enqueued and the log names the uuid of the one left out
- @e2e exclude pinned by `DeferredChunksFitTheJobLimitTest::testAnEntryTooLargeForAnyJobIsNamedAndTheRestGo`
