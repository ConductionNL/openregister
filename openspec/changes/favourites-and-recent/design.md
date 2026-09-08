# Design: favourites and recent

## D-1: state beside the object, never in it

A star and a view are facts about a user, not about the object. They live in
two small tables keyed by (user, object uuid) and never touch the object row,
so no audit entry, no version, no lock. Deleting an object cascades both
(object-interactions, Object Deletion Cleanup).

## D-2: lenses are query filters

`_favourite=true` joins the favourites table for the current user;
`_recent=true` joins the views table and orders by last view descending. Both
compose with every other filter, so "my favourite open cases" is one query.
A view is written on the object read that a detail page makes, throttled to
one row per user, object and minute.

## D-3: the marker rides `@self`

Object reads carry `@self.favourite: true|false` for the current user, so
`CnDetailPage` can render a star from data it already has. The list read
carries it too, at the cost of one join.

## D-4: kind

Code, in OpenRegister. Consuming apps add chips in config.
