# Proposal: example-set-app-config-at-least

## Why

pipelinq's Zuiddrecht set declares `ticket_reference_counter` 423, the last ticket number its seeded tickets use. The app moves that counter on every time a request or complaint is created without a number, including tickets that later fragments write in the same install. REFRESH-8f then reported "written but not kept" for the counter. A plain value has only two options, and both are wrong:
- writing it exactly lowers a counter the app has moved past, after which new tickets repeat numbers;
- leaving it out lets a fresh counter hand out numbers the set already uses.

## What changes

- An integer app config entry MAY carry `atLeast: true`. It is a floor for a running counter:
  - install raises the key to the value when it is missing or lower, and leaves a higher key as it is;
  - the read-back reports the entry lost only when the key fell below the value;
  - remove leaves a key the app moved on, as it already does for any value changed since.
- `atLeast` on a generated or non-integer entry refuses the set.

## Not changed

- Entries without `atLeast` behave as before.

## Rollback

Revert the commit. A set that declares `atLeast` then writes the value exactly again.
