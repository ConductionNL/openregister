# Draft versions

Work on a named draft of a record while everybody else keeps seeing the
published version. Open the draft on the real public website before it goes
live, through a preview link that expires. Promote it when it is ready, and it
becomes the published version.

A draft is not a `published: false` flag and not a second object. It is a
working copy kept beside the live object: one row per draft, holding only the
fields that differ from the published version (the delta). The live object is
not touched until the draft is promoted, and no list, count, facet, search or
relation query ever returns a draft as an object.

## Who sees a draft

A draft is visible to the person who made it and to anybody who may change the
object (`update` on its schema). Everybody else does not see it at all: it is
left out of their draft list, and asking for it by key answers 404, the same
answer as a draft that does not exist.

Making a draft needs the right to change the object, and so does promoting one.

## Keys

Each draft has a key, unique per object: lower-case letters and digits joined by
single hyphens, at most 64 characters (`status-update`, `herziening-2`). The key
`main` is reserved for the published version and is refused with 422, as is a
key the object already uses.

## The API

All routes sit under `/api/objects/{register}/{schema}/{id}`.

| Route | What it does |
| --- | --- |
| `GET  .../versions` | The drafts you may see: key, name, creator, base version, dates |
| `POST .../versions` | Make a draft: `key`, optional `name`, and the changed fields in `object` |
| `GET  .../versions/{key}` | The object as the draft would make it |
| `PUT  .../versions/{key}` | Add changes; a field set back to its published value leaves the delta |
| `DELETE .../versions/{key}` | Discard the draft; the published object is not touched |
| `POST .../versions/{key}/promote` | Make the draft the published version (see below) |
| `POST .../versions/{key}/preview` | Make a preview link (see below) |

```bash
curl -u behandelaar:pass -X POST \
  https://your-instance/index.php/apps/openregister/api/objects/vergunningen/aanvraag/3f2b0c9a-.../versions \
  -H 'Content-Type: application/json' \
  -d '{"key": "status-update", "name": "Status update", "object": {"status": "in_behandeling"}}'
```

### Reading an object at a draft

The ordinary object read takes `?version=<key>`:

- `?version=main`, or no `version`, is the published object, unchanged.
- `?version=status-update` is the published object with the draft's delta
  merged in, rendered the same way (relations in the delta resolve like any
  property). `@self._version` says which draft it is: `{key, name, base}`.

### Draft keys on a list

A list read with `_drafts=true` adds, on every returned object, the keys of the
drafts you may see in `@self.drafts` (an empty list when there are none). The
objects in the list are exactly the same with and without the parameter.

## Promotion

`POST .../versions/{key}/promote` makes the draft the published version.

Before anything is saved, every field the draft changes is checked against the
published object. The draft remembers the object's version when it was made
(its base). If the published value of a field still equals its value at the
base, or already equals the draft's value, the field is fine. If the field was
changed on the published object to something else, it is a conflict, and the
promotion answers **409** with each conflicting field, the draft's value and the
published value:

```json
{
  "error": "The published object changed the same fields since the draft was made: status.",
  "conflicts": [
    {"field": "status", "draft": "in_behandeling", "live": "geweigerd"}
  ]
}
```

Fields the draft does not touch may have changed freely; they are kept. When the
base can no longer be rebuilt from the audit trail, every field whose published
value differs from the draft's counts as a conflict, so a change is never
overwritten without anybody knowing.

Without conflicts, the draft's data goes through the normal save path: schema
validation, access rules, hooks, lifecycle guards and the audit trail all run
once, exactly as for an ordinary edit. A save that is refused answers with the
same refusal (422 for validation) and the draft stays. An approval chain that
holds the change answers 202 and keeps the draft too. When the save succeeds,
a `draft.promoted` entry is written on the audit trail (the draft key, the
fields and their previous published values) and the draft is deleted, in the
same transaction.

### Forcing a promotion

`?force=true` promotes over conflicts. It is for administrators only; anybody
else gets **403**. The `draft.promoted` entry then records `forced: true` and
`overwritten`, the list of fields whose published value was replaced.

## Preview on the public website

A schema may declare a preview URL template in its configuration:

```json
{
  "configuration": {
    "previewUrl": "https://www.voorbeeld.nl/preview/{uuid}?versie={version}&token={token}"
  }
}
```

The template must be an absolute `http` or `https` URL and may use only
`{uuid}` (the object), `{version}` (the draft key) and `{token}` (the preview
link's token). A template with another placeholder or an unbalanced brace is
dropped when the schema is saved, and reported like any other configuration key
that did not pass.

`POST .../versions/{key}/preview` makes a preview link: an
[access link](access-links.md) whose subject is this one draft. It is read-only,
always expires (after 24 hours unless the request passes `expiresAt`), and can
be revoked like any other access link. The answer is the link, plus
`previewUrl` filled in for this draft, or `null` when the schema declares no
template.

The website calls the access link route with the token,
`GET /api/public/links/{token}`, and gets the published object with the draft
merged in, filtered by the same property rules as any access link, plus
`version: {key, name}` so it can show a preview banner. After the link expires
or is revoked, and once the draft is promoted or discarded, the route answers
404.

A preview link can only be made through the draft's own route, after the check
that you may see the draft; the general access link route refuses this kind of
link.

## Standards and decisions

- Publication stays a matter of access rules; a draft is a working copy beside
  the published object, never a hidden flag on it.
- Promotion records the previous published values on the audit trail.
- The preview route is public, read-only, scoped to one draft and expires.
