# Design: object-watchers

## D-1: watchers are not favourites

A favourite is private and silent. A watcher is a subscription: it produces
notifications and its list is visible to the object's editors. The two share
a storage shape and nothing else, so they get separate tables and separate
verbs rather than a flag on one row.

## D-2: the recipient kind, not a rule per object

Declaring `{"watchers": true}` once on a schema's notification rules is what
makes the subscription useful. The dispatcher resolves the watcher list at
dispatch time (the engine's rule: rules sourced from the annotation,
evaluated at dispatch) and merges it with the other recipient blocks,
deduplicating by user. Preferences and quiet hours apply as for any
recipient.

## D-3: RBAC is checked at dispatch, and the list heals

A watcher who can no longer read the object is skipped and removed. Checking
at dispatch, not at subscribe time only, is what keeps a sensitive case from
leaking to a user whose group changed.

## D-4: who sees the list

Watching is a fact about the object's audience, so a user with `update` may
read the list, and only `manage` may change another user's subscription.
A watcher can always remove themselves.

## D-5: kind

Code, in OpenRegister. A consuming app adds one recipient block and one
manifest action.
