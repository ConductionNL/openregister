# Design: view-group-share

## D-1: groups, not users

The row asks for department or role permissions. Nextcloud groups are the
fleet's departments and roles (rbac-department-role-matrix keys on them),
so a share names a group. Sharing with one user is a group of one and is
not added.

## D-2: write is the query, not the share

A `write` member changes what the view finds and how it looks, which is the
collaborative case. Who the view is shared with stays the owner's decision,
so a member cannot widen the audience.

## D-3: the list query

`findAllFor(user)` unions owner, public and `sharedWith` membership in SQL.
`sharedWith` is a JSON column; membership is matched against the user's
groups resolved once per request.

## D-4: kind

Code, in OpenRegister. The control is nextcloud-vue's open change.
