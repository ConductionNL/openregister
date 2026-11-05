# Taking a lock needs update permission

## Why
`POST /api/objects/{register}/{schema}/{id}/lock` checked only that the caller could find the object (read RBAC). A lock refuses every other writer, and one taken without a duration holds until it is unlocked, so any reader could freeze a record for everybody who may change it. pipelinq now locks finished tickets this way (board PqTicket, design-system #821), which makes the gap reachable from a product screen.

## What changes
`LockHandler` asks `PermissionHandler::checkPermission(schema, 'update', …, object)` before a user takes a lock on a stored object; the controller answers a refusal with 403. A flow run's lock (`runUuid`) is asked the same, against the user the run acts as. Advisory (pre-creation) locks are unchanged.
