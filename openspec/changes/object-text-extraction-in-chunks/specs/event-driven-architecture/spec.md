# event-driven-architecture

## ADDED Requirements

### Requirement: Object text extraction in background mode is deferred in chunks

With the extraction mode `background` (the default), the object change
listener MUST NOT queue one background job per created or updated object. It
MUST hand the object id to the listener deferral service, which queues
object-extraction chunk jobs of at most `textExtractionChunkSize` objects (app
config, default 100; a value below 1 MUST be read as 100), so N object events
in one process leave at most ⌈N / chunk size⌉ jobs. An entry MUST carry only
the object id, which is also its dedupe key, so an object saved twice in one
process is extracted once. A chunk job MUST extract its objects one by one,
MUST skip an object that no longer exists, MUST continue after a failure on
one object, and MUST extract nothing when `objectManagement.objectExtractionMode`
is `none`. With `openregister/listenerDeferral` set to `inline` the listener
MUST queue one per-object extraction job per object, as before this
requirement.

See: "Deferred listener jobs MUST be chunk-level, idempotent, and stale-safe"
and "A deferred listener chunk fits the job argument limit" in this spec.

#### Scenario: A flow run writing objects item by item leaves chunk jobs

- GIVEN extraction mode `background` and no `textExtractionChunkSize` set
- WHEN one process saves 250 objects one by one
- THEN 3 chunk jobs MUST be queued, holding objects 1–100, 101–200 and 201–250
- AND no per-object extraction job MUST be queued
- @e2e exclude pinned by `ObjectTextExtractionInChunksTest::testObjectsOfOneProcessAreQueuedInChunks`; measured on a rig in openregister#4241

#### Scenario: The chunk size is configurable

- GIVEN `textExtractionChunkSize` set to 25
- WHEN one process saves 60 objects
- THEN 3 chunk jobs MUST be queued, holding 25, 25 and 10 objects
- @e2e exclude pinned by `ObjectTextExtractionInChunksTest::testTheChunkSizeIsTheAppConfig`

#### Scenario: An invalid chunk size falls back to the default

- GIVEN `textExtractionChunkSize` set to 0
- WHEN one process saves 150 objects
- THEN 2 chunk jobs MUST be queued, holding 100 and 50 objects
- @e2e exclude pinned by `ObjectTextExtractionInChunksTest::testAnInvalidChunkSizeFallsBackToTheDefault`

#### Scenario: A chunk of 100 fits the smallest job argument limit

- GIVEN 100 objects with seven-digit ids saved in one process
- WHEN the buffer is flushed
- THEN one job MUST be queued
- AND its JSON argument MUST be at most 4,000 characters
- @e2e exclude pinned by `ObjectTextExtractionInChunksTest::testAChunkOfOneHundredFitsTheSmallestJobArgumentLimit`

#### Scenario: An object saved twice in one process is extracted once

- GIVEN one process that creates object 7 and then updates it
- WHEN the buffer is flushed
- THEN one chunk job MUST be queued, holding object 7 once
- @e2e exclude pinned by `ObjectTextExtractionInChunksTest::testAnObjectSavedTwiceInOneProcessIsQueuedOnce`

#### Scenario: A failure on one object does not stop the chunk

- GIVEN a chunk job for objects 1, 2 and 3, where extracting object 2 fails
- WHEN the job runs
- THEN objects 1, 2 and 3 MUST each be attempted once, in that order
- AND the failure MUST be logged with the object id
- @e2e exclude pinned by `ObjectTextExtractionChunkJobTest::testAFailureOnOneObjectDoesNotStopTheChunk`

#### Scenario: Object extraction switched off makes a chunk a no-op

- GIVEN `objectManagement.objectExtractionMode` set to `none`
- WHEN a chunk job runs
- THEN no object MUST be extracted
- @e2e exclude pinned by `ObjectTextExtractionChunkJobTest::testNothingIsExtractedWhenObjectExtractionIsOff`

#### Scenario: The kill switch restores one job per object

- GIVEN `openregister/listenerDeferral` set to `inline`
- WHEN one process saves 5 objects
- THEN 5 per-object extraction jobs MUST be queued
- AND no chunk job MUST be queued
- @e2e exclude pinned by `ObjectTextExtractionInChunksTest::testTheKillSwitchQueuesOneJobPerObject`
