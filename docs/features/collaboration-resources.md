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

## What is not there yet

Adding a record to a collection and removing it are handled by Nextcloud
itself. Tying those changes to the record's update rights and writing them to
the record's audit trail are planned.
