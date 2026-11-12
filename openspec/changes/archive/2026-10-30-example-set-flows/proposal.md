# Proposal: example-set-flows

## Why

An example set fills an instance with a believable municipality, but it could not ship a flow. The flow pages of the
apps (dossiq's case type flow, board DqFlow "Omgevingsvergunning regulier") therefore showed whatever flow happened to be
lying around, or an empty canvas. A flow could only arrive through a register's `x-openregister-flows`, which ships it to
every install of the app, not only to the demo.

## What changes

- A fragment may declare `flows`: keyed rows with `name`, `description`, `trigger`, `triggerRegister`, `triggerSchema`,
  `cron`, `executionMode`, `nodes` (with their canvas positions), `edges`, `annotations`, `limits` and `publish`
  (default true). The flow belongs to the app that ships the fragment.
- Checked before anything is written: a name, at least one step, every step an id and a type, every line between two
  steps of the flow, no other fields.
- Written switched off and unowned, in the organisation new entities go to, and published as version 1 unless
  `publish: false`, the same way an app's own shipped flow arrives: nothing runs it until an administrator adopts it.
- Proved on reinstall (a lost flow is reported missing) and removed with the set, with its versions and any runs.
- `FlowService::deleteStored()` holds the delete cascade; `delete()` keeps the caller check and uses it.

## Reach

Example sets only. No schema change, no migration.
