# Design: platform-share-provider

## D-1. A face on one model, never a second store

Two share stores is two answers to who may read a record, and the wrong
one wins whichever is checked last. The provider reads and writes the
object share model that already exists, so the platform sees exactly what
the object layer enforces.

## D-2. Permission bits map explicitly, and an unmapped bit is refused

Silently ignoring a permission bit is how a share ends up granting less or
more than the person who made it believes. Each bit maps to an object verb
or is refused, naming the bit.

## D-3. A schema opts in

Making every register shareable through the platform surface offers
sharing on reference data and on rows nobody should hand out. The schema
declares it.

## D-4. kind

Code, in OpenRegister.
