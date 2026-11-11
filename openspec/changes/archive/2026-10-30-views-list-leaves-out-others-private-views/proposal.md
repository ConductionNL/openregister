# The views list leaves out other users' private views

## Why
Listing views (`GET /api/views`) returned every view in the instance to an administrator, each with `@self.access` `write`, including other users' private views (no share, not public). Apps render that list as the reader's own panel: dossiq showed admin's private "Termijn deze week" under Pieter's "Gedeeld met mij" because Pieter is in the admin group.

## What changes
The list holds only what a view grants the caller: their own views, views shared with them or a group they are in, and public views, for administrators too. An administrator's reach to another user's view is unchanged on the paths that name a view by id (open, update, delete).
