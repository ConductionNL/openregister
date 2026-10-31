# An example set adopts the task or account it declared when it already exists

## Why
On :8080 a refresh failed on 18 tasks and one service account. The install record pointed at task uuids that no longer existed while the engine held a task with the same key, so writing the task again hit the unique task key (`or_tasks_key_uniq`). A service account the set declares existed already, and creating it hit the users table's primary key, because the user backend still answered that it did not exist.

## What changes
- A task whose key an engine task already holds is adopted: the record points at that task, nothing is written twice.
- A declared service account that turns out to exist when it is created is adopted into the record, so the set uses it and remove leaves it.
