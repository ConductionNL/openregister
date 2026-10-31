# example-sets Delta: example-set-task-placeholder

## ADDED Requirements

### Requirement: A note or read state may quote a task of its fragment

A fragment's watcher, party, note, read state or flow row MAY contain `{{task:<key>.uuid}}`, where `<key>` is a task the
same fragment declares. The installer SHALL write the fragment's tasks before those parts and fill the placeholder with
the written task's uuid. The check SHALL accept the placeholder for a declared task before it is written, and SHALL
refuse the set when the key names no task of the fragment.

#### Scenario: A note on a task

- **GIVEN** a fragment declares the task `hoorzitting-inplannen` and Pieter's note with `objectUuid`
  `{{task:hoorzitting-inplannen.uuid}}`
- **WHEN** the set is installed
- **THEN** the note is written on that task

#### Scenario: An unknown task

- **GIVEN** a note quotes `{{task:nope.uuid}}` and the fragment declares no task `nope`
- **THEN** the check refuses the set with "cannot resolve"
