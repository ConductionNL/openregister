## ADDED Requirements

### Requirement: A task may name the app action that completes it

A task MAY carry `metadata.completeVia` with a non-empty `app` and a
non-empty `action`: the app whose own checks a completion must pass and the
portal action of that app that completes the task. A `completeVia` missing
either value SHALL be ignored.

The portal seam SHALL refuse a direct completion of a task that names an app
action, after the party check and before anything is stored, with HTTP 409,
code `complete-via-app`, and `completeVia` naming the app and the action. A
subject who is not the task's party SHALL still read the task as absent.

The task service's portal completion SHALL complete such a task only when the
in-process caller names the same app (`viaApp`). The task row SHALL carry the
key unchanged in `metadata`, so a portal can open the named action instead of
its generic form.

#### Scenario: The party is sent to the app action

- GIVEN an open external task for `party:bsn-1` with `metadata.completeVia` = `{app: dossiq, action: completeChecklistTask}`
- WHEN subject `bsn-1` posts a completion to the portal seam
- THEN the answer is 409 with code `complete-via-app` and `completeVia` = `{app: dossiq, action: completeChecklistTask}`
- AND no file is stored and the task stays open

#### Scenario: The named app completes the task

- GIVEN the same task
- WHEN dossiq completes it in-process for subject `bsn-1` with `viaApp` = `dossiq`
- THEN the task is completed

#### Scenario: Another app or a stranger cannot

- GIVEN the same task
- WHEN another app completes it with `viaApp` = `pipelinq`
- THEN the completion is refused with `complete-via-app`
- WHEN subject `bsn-2` completes it
- THEN the completion is refused as an access denial, read as an absent task

#### Scenario: Half a completeVia locks nothing

- GIVEN a task whose `metadata.completeVia` names an app but no action
- WHEN its party completes it through the portal seam
- THEN the completion proceeds as for any portal task
