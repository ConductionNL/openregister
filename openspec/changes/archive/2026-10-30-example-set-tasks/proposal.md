# Proposal: example-set-tasks

## Why

An example set seeds the records a demo needs, but a task lives in OpenRegister's task engine, not in a register, so
no fragment could put one on its objects. Dossiq's case page draws the tasks of a case (DqZaakTaken: one open, three
done, each with its handler and deadline), and the Zuiddrecht example set's in-progress case had none.

## What changes

A fragment may declare `tasks`: a key and the task's fields (title, description, the object it is on, state,
assignee, candidate groups, priority, due date, and for a finished task when and by whom). The check refuses a task
without a title or object, one assigned to an account the set does not create or adopt, an unknown state and a date
that is not one. Install writes each task once through the task service's trusted path, so a task may be born
completed, records its uuid, and proves it is still there. Remove deletes the tasks before the objects they are on.
