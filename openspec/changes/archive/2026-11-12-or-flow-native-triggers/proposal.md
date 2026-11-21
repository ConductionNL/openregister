# Proposal: or-flow-native-triggers

## Summary

Fire flows on Nextcloud events whose subject is not an OpenRegister object — a
file written, a user created. These need no object to run about; the event's
details ride on the run as a payload, and the run's first item is seeded from it.
This is the remaining half of #2068's "a Nextcloud-native trigger set".

## Why

The object-lifecycle triggers (#2104, #2112) cover every event about an
OpenRegister object. But n8n-parity means triggering on the platform's own
events too — the moment a file lands, the moment a user is created. Those have no
register or schema; the object-centric trigger path cannot carry them. The run
model already anticipated this (the worker comments that a subjectless run is
"seeded from its payload"), so the missing piece is small: a listener that turns
the event into a payload, and the worker actually seeding from it.

## What Changes

- **`NativeFlowTriggerListener`** translates file (`NodeCreated` / `NodeWritten`
  / `NodeDeleted`) and user (`UserCreated` / `UserDeleted`) events into
  `file.created` / `file.updated` / `file.deleted` / `user.created` /
  `user.deleted` triggers. The subject is empty — a file is not an OR object — so
  a flow matches on the trigger id alone. The event's details (a file's id, path,
  name, mimetype; a user's uid) go on the run context as `payload`, each field
  read defensively so one unreadable field never loses the whole trigger.
- **`FlowRunWorker`** seeds a subjectless run's first item from `context.payload`,
  so a flow reads a file's path or a user's id exactly as it would an object's
  fields. (A subjectless run already fell back to a bare marking holder; now it
  also carries its payload.)
- **The event catalog** gains the File and User trigger groups so the builder can
  offer them.

## Out of scope

- Share, tag, calendar and scheduled triggers. The mechanism here (payload-seeded
  subjectless runs) is exactly what they will reuse — each is then a few lines in
  the same listener — but every event family has its own payload shape and is
  added on its own. Scheduled triggers are a different shape again (time, not an
  event) and are their own change.
