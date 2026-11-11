# example-sets Delta: example-set-export-runs

## ADDED Requirements

### Requirement: A fragment may give an account an export it made

A fragment MAY declare `exportRuns`. Each row SHALL name an account the set creates or adopts, a plain file name, and its content as text or as `contentBase64` (at most 64 kB); it MAY name the format, row count, register and schema names, the moment it was made and its download count. Install SHALL write the file into the account's `Exports` folder and record an export run naming it (source `example-set`, actor the account), produced at the declared moment and expiring after the usual retention from then. The proof SHALL name a run or file that is gone. Remove SHALL delete the file and the run. Any other field, an account outside the set, a file name with a folder or a leading dot, or missing or oversized content SHALL refuse the set before anything is written.

#### Scenario: Pieter's export of last quarter's Woo requests
- **GIVEN** an export row for `pieter`, `woo-2026-q3.csv`, with two CSV lines and `producedAt` two days back
- **WHEN** the set is installed and later removed
- **THEN** `Exports/woo-2026-q3.csv` SHALL hold the two lines and a run SHALL name it, produced two days back; after remove neither SHALL exist
