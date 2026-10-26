# example-sets Delta

**Status**: proposed
**Scope**: openregister
**OpenSpec changes**:

- [example-set-notes-and-read-states](../../)

## Purpose

An example set can show a working day: what its people opened and what they wrote down.

## ADDED Requirements

### Requirement: A fragment may leave notes of the set's own accounts on its objects

A fragment MAY declare `notes` rows `{ user, objectUuid, message, createdAt? }`. The installer SHALL write each as a
Nextcloud comment of object type `openregister` on the object, by the user, with the message, created at `createdAt` (or
now), once, and record it. The check SHALL refuse a row whose user is not an account the set creates or adopts, that names
no object or message, carries another field, or whose `createdAt` is not a date and time.

#### Scenario: A note of this afternoon

- **GIVEN** a note by Esra on case-1 with `createdAt: {{datetime:0 15:40}}`
- **THEN** case-1 carries Esra's note created at 15:40 on the install day, and a second install keeps it

### Requirement: A fragment may say which objects the set's own accounts opened and when

A fragment MAY declare `readStates` rows `{ user, objectUuid, register?, schema?, seenAt? }`. The installer SHALL record
that the user opened the object at `seenAt` (or now), once, and record the pair. The check SHALL refuse a row whose user is
not an account the set creates or adopts, that names no object, carries another field, or whose `seenAt` is not a date
and time.

#### Scenario: A case opened this morning

- **GIVEN** a read state of Esra on case-1 with `seenAt: {{datetime:0 09:12}}`
- **THEN** case-1 reads as opened by Esra at 09:12 on the install day

### Requirement: Remove takes away the notes and read states a set wrote

Remove SHALL delete every note the record holds and put every recorded object back to unread for its user. A note or
read state that disappeared SHALL be reported missing by the next install.

#### Scenario: Removing the set

- **GIVEN** the set installed with one note and one read state
- **WHEN** the set is removed
- **THEN** the note is gone and the case is unread for Esra again
