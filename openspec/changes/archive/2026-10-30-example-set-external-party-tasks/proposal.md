## Why

A resident-facing app shows a resident their open portal tasks: openregister's external tasks, assigned to `party:<subjectRef>`, with the upload rules frozen on the task. An example set could not seed one. `ExampleSetTasks` accepts neither `performerType` nor upload rules, and it refuses any assignee that is not one of the set's own Nextcloud accounts. So the portaliq TaakAfronden board ("Stuur een kopie van uw ID-bewijs") had no task to show.

## What Changes

- A fragment's task MAY declare `performerType: external` with an `assignee` of the form `party:<reference>`. A placeholder may fill the reference, for example `party:{{cast:sanne.uid}}`.
- An external task MAY declare `uploadRules`: `required`, `maxFiles`, `maxSizeBytes` and `acceptedTypes`. Install writes them into the task's `metadata.upload`, the place the portal task seam already reads its constraints from.
- The check refuses an external task whose assignee is not a party reference, `uploadRules` on a task that is not external, and a `completedBy` on an external task.
- Install writes an external task as the fragment's app, not as the party.
