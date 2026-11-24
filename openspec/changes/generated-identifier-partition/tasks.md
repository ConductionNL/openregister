# Tasks: generated-identifier-partition

## 1. Declaration and issue

- [x] 1.1 Accept `partitionBy` on `x-openregister-generated` and refuse anything but a plain property name at schema save.
- [x] 1.2 Key the counter by sequence, partition value and period; an empty partition value keeps the unpartitioned counter; a supplied value advances its own partition.

## 2. Tests

- [x] 2.1 PHPUnit `GeneratedIdentifierPartitionTest`: two units each start at 1, the period still keys the counter, no unit keeps the shared counter, the value is encoded in the key, a supplied number advances its own unit, malformed `partitionBy` refused; golden message updated for the new key.
- [ ] 2.2 Newman/e2e on a live instance: two objects in two units in a new year both get `{year}-00001` (written with the consuming app's change, runs in the nightly).
