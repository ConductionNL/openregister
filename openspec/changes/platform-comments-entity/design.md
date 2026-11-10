# Design: platform-comments-entity

## D-1. One listener, several collections

The event is dispatched once and accepts many collections. Registering
them from the one listener keeps a single validation implementation, which
is the part that decides whether a comment can be attached to a record
that does not exist.

## D-2. Existing comments are not migrated

Comments already stored under `openregister` keep resolving because that
collection stays registered. Rewriting the stored entity name would be a
migration over every comment in the fleet to change a label, and it would
break any reference that kept the old pair.

## D-3. The collection does not change who may read

A comment's visibility is the object's visibility, before and after this
change. The collection is a name, not a permission boundary, and treating
it as one would be a second access model.

## D-4. kind

Code, in OpenRegister.
