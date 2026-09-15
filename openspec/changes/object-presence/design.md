# Design: object-presence

## D-1: heartbeat, not connection tracking

notify_push tells the server nothing about who is looking at what. A
heartbeat from the client every 30 s, expiring at 90 s, is what Deck does
and it survives a lost socket. Missing two beats reads as gone.

## D-2: push on change only

The server pushes a `presence` event on arrival, on departure and on expiry,
never on every renewal. A page with twenty readers costs twenty writes a
minute and no pushes while nothing changes.

## D-3: RBAC is the object's

The list of present users is served to anyone who may read the object, the
same rule as the object itself, and the push goes only to the users
`PermissionHandler` resolves for the object. Presence never reveals an
object to someone who cannot read it.

## D-4: no object write

Presence is not a property, a version or an audit fact. The table is pruned
by the existing sweep and its rows carry no data beyond uid, object and
time.

## D-5: kind

Code, in OpenRegister and the shared client plugin. Consuming apps place
one component.
