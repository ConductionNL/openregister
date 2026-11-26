# Tasks: generated-identifier-partition

## 1. Declaration and issue

- [x] 1.1 Accept `partitionBy` on `x-openregister-generated` and refuse anything but a plain property name at schema save.
- [x] 1.2 Key the counter by sequence, partition value and period; an empty partition value keeps the unpartitioned counter; a supplied value advances its own partition.

## 2. Tests

- [x] 2.1 PHPUnit `GeneratedIdentifierPartitionTest`: two units each start at 1, the period still keys the counter, no unit keeps the shared counter, the value is encoded in the key, a supplied number advances its own unit, malformed `partitionBy` refused; golden message updated for the new key.
- [x] 2.2 Newman/e2e on a live instance: two objects in two units in a new year both get `{year}-00001` (written with the consuming app's change, runs in the nightly).
  > 2026-11-25: `tests/integration/openregister-generated-identifier-partition.postman_collection.json`, run with Newman
  > against a sandbox Nextcloud 34 on PostgreSQL 16 (main 55f2aa3e): 13 requests, 22 assertions, 0 failed. A per-run
  > sequence (`{year}-{seq:5}`, `resetOn: year`, `partitionBy: unit`) starts fresh, the state of a new year: Finance
  > `-00001`, Housing `-00001`, Finance `-00002`; two objects without a unit `-00001`, `-00002` from the unpartitioned
  > counter; Housing with a supplied `-00010` keeps it and the next Housing is `-00011` while the next Finance is
  > `-00003`; `partitionBy: unit.name` is refused at schema save (422 naming `partitionBy`). It is OpenRegister's own
  > collection, so it does not wait for filinq's post register.
