# Widget record import

Importing a file of records into a register, through the streaming write path.

This spec covers RECORDS. File upload — moving bytes into Nextcloud storage — is
a separate concern handled by `FileService`, and nothing here parses or stores
file content beyond reading rows out of it.

## Requirement: A record import MUST write through the streaming path

An import MUST call `SaveObject::saveObjectsStreaming()` rather than
`SaveObjects::saveObjects()`.

The reason is not preference. The bulk path never consults the
reference-validation cache, so a payload whose rows reference each other pays
N×M database round-trips resolving them; the streaming path routes each row
through `saveObject()`, where the second and later occurrences of any target
UUID resolve from memory. It also consumes its input lazily, so a 200 MB
spreadsheet does not have to be resident, and it records a failed row on the
returned status instead of failing the call.

The request-scoped reference cache MUST be cleared at the start of each import,
because verdicts from an earlier import must not decide this one.

#### Scenario: An import of cross-referencing rows resolves each target once
- **GIVEN** a file of 5,000 rows, all referencing the same 3 target objects
- **WHEN** the import runs
- **THEN** the returned `BatchOperationStatus` reports 3 reference-cache misses
- **AND** the remaining reference resolutions are cache hits

#### Scenario: One bad row does not lose the import
- **GIVEN** a file of 1,000 rows where row 400 fails validation
- **WHEN** the import runs
- **THEN** rows 1–399 and 401–1000 are written
- **AND** the status reports exactly one failed row, carrying its reason

## Requirement: Column mapping MUST be confirmed, never inferred silently

The import MUST propose a mapping from file columns to schema properties, and
MUST NOT write until the user has confirmed it.

Unmatched columns MUST be shown as unmatched. Silently discarding a column the
user believed was imported is the failure that makes an import untrustworthy —
the data appears to have loaded, and the absence is discovered much later by
someone who cannot tell whether it was ever sent.

#### Scenario: An unmatched column is surfaced, not dropped
- **GIVEN** a file with a `postcode` column and a schema with no matching property
- **WHEN** the mapping step runs
- **THEN** `postcode` is listed as unmatched
- **AND** the user must either map it or explicitly choose to ignore it before proceeding

## Requirement: A dry run MUST report outcomes without writing

The import MUST offer a pass that produces the same per-row classification —
created, updated, unchanged, failed — as a real run, while writing nothing.

#### Scenario: A dry run leaves the register untouched
- **GIVEN** a register containing 10 objects
- **WHEN** a dry run of a 50-row file completes
- **THEN** the register still contains 10 objects
- **AND** the report states how many WOULD be created and updated

## Requirement: Failed rows MUST be exportable in the input's shape

The result MUST offer the failed rows as a file with the same columns as the
input, plus a reason column.

A user who imports 4,000 rows and is told "37 failed" cannot act on a count. The
correctable unit is the file they started from, minus what already worked.

#### Scenario: Failures round-trip
- **GIVEN** an import where 37 of 4,000 rows failed
- **WHEN** the user exports the failures
- **THEN** the file contains 37 rows with the original columns and a reason column
- **AND** re-importing that file after correction creates or updates only those 37

## Requirement: Re-importing the same file MUST NOT duplicate

Where the mapping nominates an identifying column, a row whose identifier already
exists MUST update rather than create.

Where no identifying column is nominated, the import MUST state plainly that
every row will be created, and MUST NOT guess an identifier from the data. A
guessed identity is silently wrong for exactly the rows a human would have
recognised as the same record.

#### Scenario: A re-dropped file updates
- **GIVEN** a file previously imported with `reference` as the identifying column
- **WHEN** the same file is imported again with one changed value
- **THEN** the status reports 0 created
- **AND** reports 1 updated and the rest unchanged

#### Scenario: No identifier means create-only, said out loud
- **GIVEN** a mapping with no identifying column nominated
- **WHEN** the user reaches the confirmation step
- **THEN** the import states that every row will be created as a new object
- **AND** does not select an identifying column on the user's behalf

## Requirement: The widget MUST delegate, not implement

The widget's drop target MUST hand the file to the import surface. It MUST NOT
parse rows, map columns, or write objects.

The widget already owns file drops for file upload. Letting it also own record
parsing would put two unrelated responsibilities behind one gesture, and the
first schema-specific parsing rule would land in a component that has no notion
of a schema.

#### Scenario: A dropped record file reaches the import surface
- **GIVEN** a user drops a CSV on the widget
- **WHEN** the drop is handled
- **THEN** the widget invokes the record-import surface with the file
- **AND** contains no parsing or object-write logic of its own
