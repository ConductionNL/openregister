# Collections across apps

Put a record in a Nextcloud collection beside the folder, the conversation and
the board that belong to it. The collection is Nextcloud's own (the
"Collaborative resources" a file or a Talk room shows in its sidebar), so the
other apps already know how to show it; OpenRegister makes the record one of
its members.

## What a record in a collection is

Every record of a schema that opts in can join a collection as a resource of
type `openregister-object`, with the id `<registerId>/<schemaId>/<uuid>`.

- **Its name, icon and link** are the record's reference card, the same one a
  pasted link to the record unfurls into.
- **Who may see it** is answered by the record itself: the question goes
  through the same read path as opening the record, with its access rules and
  its organisation. Somebody who may not read the record does not see it in
  the collection, and a question about anyone but the signed-in user is
  refused.
- **A schema decides whether its records may join.** Reference data has no
  business in a project, so the default is out.

## Letting a schema's records join

Set `collaborationResources` in the schema's configuration:

```json
{
  "configuration": {
    "collaborationResources": true
  }
}
```

Only `true` opts in. Any other value, or a schema that cannot be read, keeps
the schema's records out of every collection.

## Showing a record's collections

```
GET /apps/openregister/api/objects/{register}/{schema}/{id}/collections
```

The record is read first, as the signed-in user: an unreadable record answers
404. The answer lists the collections the reader may access, each with the
other members the reader may access, as the rich objects their own apps
describe:

```json
{
  "results": [
    {
      "id": 12,
      "name": "Bezwaar Keizersgracht 12",
      "members": [
        {"type": "file", "id": "4711", "name": "Bezwaar", "link": "…", "iconUrl": "…"},
        {"type": "room", "id": "a1b2c3", "name": "Bezwaar Keizersgracht 12", "link": "…", "iconUrl": "…"}
      ]
    }
  ]
}
```

A record of a schema that does not opt in has no collections.

## For an app built on OpenRegister

An app declares which of its schemas may join (the configuration above, in its
register file) and, if it wants, renders the record's collections on its own
page from the endpoint above. It registers no provider of its own: the
`openregister-object` provider covers every record of every register.

## Adding a record to a collection and taking it out

```
POST   /apps/openregister/api/objects/{register}/{schema}/{id}/collections   {"collectionId": 12}
DELETE /apps/openregister/api/objects/{register}/{schema}/{id}/collections/12
```

Which collections a record belongs to says what belongs together, so changing
it needs the right to update the record, not only to read it. The checks run in
this order:

1. The record is read as the signed-in user; an unreadable record answers 404.
2. The record's schema must let its records join collections; otherwise 400.
3. The user must be allowed to update the record; otherwise 403.
4. The collection must exist and be one the user may access; otherwise 404.

Every add and every removal that changed something writes one entry on the
record's audit trail, `collection.added` or `collection.removed`, naming the
collection's id and name. Adding a record that is already a member, or removing
one that is not, changes nothing and writes nothing. The answer is the record's
collections after the change, in the same shape as the read above.

## What is not there yet

Nextcloud's own collection screens (the "Collaborative resources" sidebar on a
file or a Talk room) add and remove members through Nextcloud's endpoints,
which ask OpenRegister only whether the record may be read. Those changes are
not tied to update rights and are not on the record's audit trail; use the
endpoints above where that matters.
