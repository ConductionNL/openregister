# Design: timeline-entry-visibility

## D-1: internal by default, everywhere

A missing flag means internal. The failure mode to avoid is a note written
before the flag existed appearing on a citizen's screen, so the default is
applied at read time as well as at write time.

## D-2: stored where the entry lives

A note is a Nextcloud comment; the flag goes into the comment's reference
metadata (`ICommentsManager` supports a reference id and a verb), so no
second table and no join. A feed row derives its flag from its source at
merge time; the feed stores nothing.

## D-3: the filter is enforced, not requested

A caller with `read` but not `update` on the object is served the public
view regardless of the query parameter. A portal reader never holds
`update`, so the filter cannot be bypassed by omitting it.

## D-4: flipping is a write on the object's audience

Changing `visibility` on a note writes an audit entry on the object naming
the note and the old and new value, because making an internal note public
is the act a supervisor will ask about.

## D-5: kind

Code, in OpenRegister. Consuming apps set defaults per source and place the
chip.
