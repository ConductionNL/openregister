# Record identity: addressing an object by its own key

Outside software often knows a record by its own key, not by Open Register's
uuid: a municipality code plus a case number, for example. A schema can declare
that combination as its **identity**. Software can then read, change and delete
the record by that key.

## Declaring an identity

An identity is a flag on a named uniqueness constraint in the schema's
configuration:

```json
{
  "configuration": {
    "uniqueConstraints": [
      {
        "name": "zaaksleutel",
        "properties": ["gemeentecode", "zaaknummer"],
        "action": "refuse",
        "identity": true
      }
    ]
  }
}
```

- Only a `refuse` constraint can be the identity. A `report` constraint lets a
  duplicate through, and a key that can match two records is not a key.
- A schema has at most one identity.

A schema save that breaks either rule is refused with a 400 that names the
constraint. A schema without an identity behaves exactly as before.

## Addressing a record by its key

With the identity above, these four requests act on the one case with
`gemeentecode` 0363 and `zaaknummer` Z-2026-0042:

```
GET    /api/objects/zaken-register/zaken/by-key?gemeentecode=0363&zaaknummer=Z-2026-0042
PUT    /api/objects/zaken-register/zaken/by-key?gemeentecode=0363&zaaknummer=Z-2026-0042
PATCH  /api/objects/zaken-register/zaken/by-key?gemeentecode=0363&zaaknummer=Z-2026-0042
DELETE /api/objects/zaken-register/zaken/by-key?gemeentecode=0363&zaaknummer=Z-2026-0042
```

The key is read from the query string only, so a request body cannot change
which record it writes to. The request then goes through the same path as the
uuid route, with the same access checks and the same response body.

| Answer | When |
| --- | --- |
| 400 | A value for one of the identity properties is missing. The response lists which. |
| 404 | No object you may read has this key, or the schema declares no identity. A record you may not read is reported as not found, never as forbidden. |
| 409 | More than one object has this key. This only happens with data from before the identity was declared. The response lists the uuids. |

The uuid stays the primary key and the id in every relation. The key is how
outside software finds a record.

## Identity values do not change

Once an object exists, an update that changes one of its identity values is
refused with a 422 that names the property. A value the object does not carry
yet, for example on a record from before the identity was declared, may be
filled in. The audited schema migration is the one way to rewrite identity
values.
