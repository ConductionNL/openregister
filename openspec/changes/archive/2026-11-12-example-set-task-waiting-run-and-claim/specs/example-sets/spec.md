# example-sets Delta

## ADDED Requirements

### Requirement: A fragment's flow may carry a run that waits on a task

A fragment's flow MAY declare, besides finished runs, a waiting run: `status` `suspended`, a `key`, and a `node` that is an `openregister.user-task` step of that flow. Install SHALL write it as the user-task step leaves a live wait: parked on that step with one item, its resume slot empty until a task of the same fragment names it. The flow's record entry SHALL carry the run's uuid under its key, so a task quotes it as `{{flow:<flow>.runs.<run>}}`. The check SHALL refuse a waiting run on any other step type, a waiting run without a key, and a waiting run that no task in its fragment names.

#### Scenario: A hearing step waits on its task
- **GIVEN** a fragment declares the bezwaar flow with a waiting run `wacht` on the user-task step `hoorzitting`, and a task with `runUuid` `{{flow:bezwaar.runs.wacht}}` and `nodeId` `hoorzitting`
- **WHEN** the set is installed
- **THEN** the run SHALL be `suspended` with a token on `hoorzitting`, its slot SHALL hold the task's uuid, its heartbeat SHALL be set, and the task SHALL carry the run's uuid and the step

#### Scenario: A waiting run no task names
- **GIVEN** a fragment's flow declares a waiting run and no task of the fragment quotes it
- **WHEN** the set is checked
- **THEN** the check SHALL refuse the run and write nothing

#### Scenario: A waiting run on a step that is not a user task
- **GIVEN** a fragment's flow declares a waiting run on a step of type `dossiq.askPerson`
- **WHEN** the set is checked
- **THEN** the check SHALL refuse the run and write nothing

### Requirement: A fragment's task may name the run it waits in and carry its claim

A fragment's task MAY declare `runUuid` and `nodeId`, always together, where `nodeId` is the step its run waits on. A task MAY declare `claimedBy`, the task's assignee, and `claimedAt`, a date that is not in the future. Install SHALL write one `claim` entry in the task's audit by `claimedBy` at `claimedAt`, after the `create` entry.

#### Scenario: A claimed task reads as picked up
- **GIVEN** a fragment's task assigned to Pieter declares `claimedBy` Pieter and `claimedAt` yesterday at 09:05
- **WHEN** the set is installed
- **THEN** the task's audit SHALL hold a `claim` entry by Pieter dated yesterday at 09:05

#### Scenario: A task names a run and no step
- **GIVEN** a fragment's task declares `runUuid` and no `nodeId`
- **WHEN** the set is checked
- **THEN** the check SHALL refuse the task and write nothing

## MODIFIED Requirements

### Requirement: A fragment flow may carry finished runs

A fragment's flow MAY carry runs on a subject object. A run is finished (its status is terminal) unless it is a waiting run as the requirement above describes; a queued or running run SHALL be refused, because the flow worker would execute it.

#### Scenario: a case's run history
- **GIVEN** a flow entry with a completed and a failed run on a case
- **WHEN** the set is installed
- **THEN** the case's finished runs read both, and removing the set removes them

#### Scenario: a waiting run
- **WHEN** a run declares status `suspended` without a key or a user-task step
- **THEN** the check refuses it and nothing is written

#### Scenario: a running run
- **WHEN** a run declares status `running`
- **THEN** the check refuses it and nothing is written
