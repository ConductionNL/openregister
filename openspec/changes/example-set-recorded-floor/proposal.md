# Proposal: example-set-recorded-floor

## Why

REFRESH-8g on :8080: pipelinq's set declares `ticket_reference_counter` as a floor of 423 (#4972), but the install record already held the entry from before floors existed, `{new: '402', type: int}`. Install skips a key the record holds, so the floor was never applied: the instance kept 404. The read-back still compared exactly against '402', and reported the counter lost every time the app moved it on.

## What changes

- When a fragment declares an app config entry as a floor (`atLeast`) and the record holds that entry without `atLeast`, or with a lower floor, install brings the entry up to the declared floor:
  - the key is raised to the floor when it holds less, and left as it is when it holds the floor or more;
  - the record's entry takes the floor (`new`, `atLeast`, `type: int`) and keeps `had` and `old`, so remove still puts back what was there before the set;
  - the report says `raised` when the key was written, `kept` otherwise.
- The read-back then uses the floor.

## Not changed

- A recorded entry that is already a floor at or above the declared value is left as it is.
- Entries that are not floors behave as before.

## Rollback

Revert the commit.
