# Proposal: example-set-notes-and-read-states

## Why

dossiq's Close out your day (board DqDagAfsluiten) lists what the reader opened today and the notes they recorded today.
Both live outside a register: what a user opened is OpenRegister's read state, and a note is a Nextcloud comment on the
object. An example set could declare objects, tasks, views and follows, but not that Pieter opened a case this morning
or wrote a note on one this afternoon, so the page showed only empty states on a fresh demo.

## What changes

A fragment may declare two more kinds of rows, written after its objects, recorded, proved and removed like follows:

- `notes`: `{ user, objectUuid, message, createdAt? }`. A note of one of the set's own accounts on an object, written as
  a Nextcloud comment of object type `openregister` (the one the notes endpoints read) at `createdAt`.
- `readStates`: `{ user, objectUuid, register?, schema?, seenAt? }`. The object counts as opened by that account at
  `seenAt`.

The user must be an account the set creates or adopts; an unknown field, a missing object or message, or a moment that is
not a date and time is refused at check time. Remove deletes the notes and puts the objects back to unread.

## Scope

OpenRegister's example-set loader; dossiq's Zuiddrecht fragment uses both (R22-DASH).
