# Access links

Share one record with somebody who has no account. The link expires, says what
its holder may do, and every use of it is on the audit trail under the link's
own name.

A bezwaarmaker, an externe adviseur and an architect all need one dossier, and
none of them is going to get a login. Before this, the only answers were an
account nobody will create or an email attachment nobody can revoke. An access
link is the third answer: it opens one object, one saved view or one file, and
nothing else.

## What a link is

A link is a grant whose principal is the link itself. It never resolves to a
user account, so a forwarded link borrows nobody's rights, and the audit trail
can say that the act was the link's rather than a person's.

Every link carries:

- **a random anchor.** The URL is the secret. Knowing the case number tells you
  nothing about the link, because the anchor is drawn from the secure random
  generator and never from the record.
- **a declared capability set.** Read, read and comment, or read and upload. The
  default is read. Anything the link did not declare is refused with a 403, so a
  capability added to OpenRegister later does not widen a link minted today.
- **an expiry.** Required. A link without an end date is a door nobody closes,
  so a mint without one is refused and names the requirement.
- **an optional password.** Checked at use, not at render. Present it in the
  `X-OpenRegister-Link-Password` header so it never lands in an access log.

## Mint a link

```bash
curl -u behandelaar:pass -X POST \
  https://your-instance/index.php/apps/openregister/api/access-links \
  -H 'Content-Type: application/json' \
  -d '{
    "subjectType": "object",
    "subjectId": "3f2b0c9a-...",
    "capabilities": ["read", "comment"],
    "expiresAt": "2026-10-15T00:00:00+00:00",
    "password": "Geheim-2026",
    "label": "Advies omgevingsdienst"
  }'
```

The response carries the row and the `url` you hand out. `subjectType` is
`object`, `view` or `file`. A file subject is written as
`<object uuid>/<file id>`, so the object that owns the file is named in the
link rather than looked up from it.

## Open a link

The holder needs no account and no session:

```bash
curl https://your-instance/index.php/apps/openregister/api/public/links/<anchor> \
  -H 'X-OpenRegister-Link-Password: Geheim-2026'
```

The answer carries `link` (what the holder may do, and until when), `subject`
(the record) and `timeline` (the public entries on it).

## What a link cannot see

A link is a reader, not an exemption. Its reads run through the same filters a
person's do:

- a property the schema marks `writeOnly` is never served;
- a property behind property-level authorisation is dropped, because an
  anonymous reader qualifies for no group;
- the timeline is served at `public` only, so an internal note stays internal;
- a file the object does not carry is not served;
- `@self.owner`, `@self.organisation`, `@self.folder` and
  `@self.authorization` never leave. Publishing a record is not publishing who
  administers it.

If the schema behind an object cannot be resolved, the link publishes no
properties at all. Failing closed there is deliberate: a reader that published
the record when it could not read the rules would look exactly like a working
one.

## Revoke a link

```bash
curl -u behandelaar:pass -X DELETE \
  https://your-instance/index.php/apps/openregister/api/access-links/<id>
```

Revocation takes effect on the next use, because every use reads the row. To
pause a link instead of ending it, `PUT` `{"disabled": true}` to the same
address and `{"disabled": false}` to bring it back.

A revoked, paused, expired or unknown link all answer the same **404**. That is
deliberate. A 403 would confirm that the record exists, which is the one fact a
revoked link should stop telling, and a reduced page would be worse still,
because a partial answer looks like the whole answer. **410 Gone** is never
sent for the same reason: Gone confirms that a link once existed.

The one place a distinct answer is right is **401**, when a live link carries a
password that was not given or did not verify. Whoever holds the anchor already
knows the link exists, so the 401 leaks nothing, and without it there is no way
to ask for the password.

## The audit trail

Every open, comment and upload writes an entry naming the link, the act, the
time and the calling address. The actor is `link:<uuid>`, never a user id, so a
publication that turns out to have been wrong can be reconstructed from the
trail without guessing which person was behind which act.

## Endpoints

| Method | Path | Who |
|---|---|---|
| `GET` | `/api/public/links/{anchor}` | the holder, no account |
| `POST` | `/api/public/links/{anchor}/comments` | the holder, needs `comment` |
| `POST` | `/api/public/links/{anchor}/files` | the holder, needs `upload` |
| `POST` | `/api/access-links` | the minter |
| `GET` | `/api/access-links` | the minter |
| `PUT` | `/api/access-links/{id}` | the minter |
| `DELETE` | `/api/access-links/{id}` | the minter |

Next: read [versioning and audit](versioning-and-audit.md) to see where a
link's acts land, and [views](views.md) to build the saved view you publish as a
besluitenlijst.
