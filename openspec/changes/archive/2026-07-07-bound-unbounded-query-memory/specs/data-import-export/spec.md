## ADDED Requirements

### Requirement: Bulk data paths are memory-bounded

Bulk data paths SHALL NOT load an unbounded amount of data into memory in a
single pass — this covers schema property exploration, object export, object
import, and file text extraction. Each SHALL either sample, page, chunk, or
size-guard its input, and any cap applied SHALL be logged, never silent.

#### Scenario: Schema exploration samples rather than full-scans

- **WHEN** schema property exploration runs on a schema with a very large object
  count
- **THEN** it analyses a bounded sample
- **AND** the result indicates it is a sample ("sampled N of M")

#### Scenario: Export streams large result sets

- **WHEN** an export is requested for a large object set
- **THEN** results are fetched in bounded batches and streamed into the writer
- **AND** if any cap is applied, the number of omitted rows is logged

#### Scenario: Import processes in chunks

- **WHEN** a large import payload is processed
- **THEN** objects are persisted in bounded chunks (multiple save operations)
- **AND** the whole payload is not buffered before a single save

#### Scenario: Oversized file skips extraction

- **WHEN** a file exceeds the configured extraction size ceiling
- **THEN** its content is not loaded into memory
- **AND** it is skipped with a logged, non-fatal outcome
