# Breaking schema changes: who is told

When a functional administrator changes a schema in a way that can break the
software built on it (a removed property, a narrowed type, a new required
field), Open Register refuses the change until it is acknowledged. Before
acknowledging, the administrator sees who will be affected. Once the change is
applied, those people are told.

## Seeing who will be affected

An update that would break the schema answers `409` unless it carries
`acknowledgeBreaking: true`. The answer says who builds on the schema:

```json
{
  "error": "Schema change classified breaking; acknowledgeBreaking required.",
  "classification": "breaking",
  "changes": ["…"],
  "affectedCallers": {
    "recorded": true,
    "count": 2,
    "top": [
      { "principal": "leverancier-a", "calls": 1840, "lastSeen": "2026-11-12T09:14:03+00:00" },
      { "principal": "leverancier-b", "calls": 312, "lastSeen": "2026-11-02T16:40:51+00:00" }
    ]
  },
  "followers": 1
}
```

- `affectedCallers.count` is the number of accounts that called this schema's
  object routes in the last 90 days; `top` lists the ten busiest, with their
  number of calls and their last call.
- `followers` is the number of users who follow the schema's changes.
- When the caller record is switched off, `affectedCallers.recorded` is `false`
  with a message instead of a count: the callers are unknown, not zero, and
  only followers will be told.

## Acknowledging, with a note

Send the same update again with `acknowledgeBreaking: true`. Add
`changeNotice` to tell the people who are notified what to do, for example
`"Field toelichting is removed, use omschrijving"`. The note is plain text of at
most 500 characters.

After the change is applied, a background job sends a Nextcloud notification to
every account that called the schema in the last 90 days and to every follower,
once each and up to 500 people. Each one is checked first: someone who can no
longer read the schema is not told. The notification names the schema, the new
version and the note, and links to the schema's changelog.

The changelog entry (`GET /api/schemas/{id}/changelog`) records how many were
told, in `noticeSentTo`.

<!-- Screenshot of the notification: to be added from a live run. -->

## Following a schema

Anyone who may read a schema can follow its breaking changes, without having
called it:

| Request | Answer |
|---|---|
| `POST /api/schemas/{id}/change-followers` | `201` when you start following, `200` when you already were |
| `DELETE /api/schemas/{id}/change-followers` | `200`; you no longer follow it |

A user who may not read the schema gets `404`, so the endpoint does not reveal
that the schema exists.

## Clients without a person behind them

A synchronisation or integration does not read notifications. For 30 days
after a breaking change, every successful read or list of the schema's objects
carries two headers:

```
OpenRegister-Schema-Changed: version="2.0.0", at="2026-11-12T10:00:00+00:00", breaking
Link: </index.php/apps/openregister/api/schemas/42/changelog>; rel="describedby"
```

A client can log or alert on the header and read the changelog it links to.

## Related

- [Content Versioning & Audit Trail](versioning-and-audit.md)
- [Webhooks & Notifications](webhooks-and-notifications.md)
