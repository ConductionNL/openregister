## ADDED Requirements

### Requirement: Scheduled notifications fire for every eligible object

The scheduled-notification job SHALL process eligible objects using a filtered,
paged query with a persisted offset cursor, so that every eligible object
eventually fires. It SHALL NOT repeatedly process only the same first N objects
and silently drop the remainder.

#### Scenario: Object beyond the batch cap still fires

- **WHEN** a schema has more eligible objects than one run's batch cap
- **AND** the job runs across multiple ticks
- **THEN** every eligible object eventually triggers its notification
- **AND** an object beyond the first batch is not permanently skipped

#### Scenario: No object fires twice per due window

- **WHEN** the offset cursor completes a full pass
- **THEN** each eligible object fired exactly once for that due window

### Requirement: Periodic sweeps are bounded and watermarked

Periodic object-sweep jobs SHALL bound per-run work with a batch cap and a
watermark/cursor, and SHALL NOT load a full object/case set into memory on every
tick. This covers temporal calculation, DPIA detection, and name warmup.

#### Scenario: Temporal sweep processes only due objects

- **WHEN** the temporal sweep runs on a large schema
- **THEN** it processes only objects whose next tier-crossing time has arrived
- **AND** it does so in bounded batches, not a full-table load
