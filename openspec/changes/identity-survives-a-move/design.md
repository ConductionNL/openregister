# Design: identity-survives-a-move

## D-1: a move is a metadata write, never a copy

The magic tables are per schema, so a move is a row in the target table
and a tombstone pointer in the source, in one transaction. The uuid is the
primary identity in both and every side table (audit, versions, files,
notes, watchers, favourites, presence, timers) is keyed on it, so nothing
there is touched.

## D-2: the number is frozen because it is data

`x-openregister-generated` values are stored in the object's data and
`generated-identifier` refuses to change them after creation. The move
validates the data against the target schema with generated properties
excluded from the "must be absent on create" rule, so a case keeps
`2026-0042` in its new schema. If the target schema declares the property
with a different sequence, the value is still kept: a number is minted once.

## D-3: the old address answers

The source table keeps a pointer row (uuid, target register, target
schema, moved at) for one year. The object resolver follows it and adds
`@self.movedTo`. A relation record's deep link is rewritten eagerly, because
`relation-resource-urls` stores the URL.

## D-4: kind

Code, in OpenRegister.
