# Design: objects-as-the-hinge-between-cases

## D-1. The reverse view is a query, not a schema

Teaching the address schema about cases makes the address schema know
about dossiq. The reverse view asks the relation store which objects point
here, groups by schema and summarises. Nothing about the referenced schema
changes, which is what lets an object type written for one app become the
hinge for another.

## D-2. A lens resolves at read and is never stored

Copying the besluit's date onto the bezwaar makes two dates that disagree
the moment one moves. The lens holds the path, not the value, and resolves
it on read. It is read-only for the same reason: writing through a lens is
writing to somebody else's record by accident.

## D-3. A withheld lens is not an empty lens

An empty field where a date should be reads as "there is no decision". If
the reader may not see the referenced object, the lens says withheld. The
difference matters most exactly when the information is sensitive.

## D-4. One list surface, declared per schema

A list page written per object type is a list page that drifts per object
type. The schema declares columns and search fields; the generic surface
renders them. That is also what makes C-case-core-24 free for every app
rather than a feature dossiq gets first.

## D-5. Inherited geography carries its provenance

A map with a pin and no explanation is a map somebody argues with. Each
inherited feature names the relation it arrived through, and a feature on
the record itself wins, so a corrected location is not overwritten by the
registry it came from.

## D-6. An intake source is an object, because there is never one

Ours is a mailbox in a settings screen, which makes a second mailbox a
code change. As objects they can be listed, switched off, permissioned and
audited like everything else.

## D-7. kind

Code, in OpenRegister.
