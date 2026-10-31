## Why

The task engine keeps a checklist on a task, and the task page draws it as the Checklist card. An example set could not give a task one: `checklist` was not among the fields `ExampleSetTasks` lets a fragment write, so the check refused the field. dossiq's DqTaak board draws a task with a checklist of four items, two of them ticked, and the example data had no such task to show.

## What Changes

- A fragment's task MAY declare `checklist`, a list of `{id, label, description?, checked?}` items, written to the engine as declared.
- The check refuses a checklist that is not a list, an item without an id or a label, an id used twice and a `checked` that is not true or false.
