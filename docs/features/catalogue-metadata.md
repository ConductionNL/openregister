# Describing a record type for a catalogue

A schema can carry the metadata a data catalogue needs: a data classification
and a catalogue block with its owner, contact, source system and update
frequency. Anyone who may list schemas can filter them by classification, and a
catalogue such as opencatalogi or a DCAT harvester reads the same fields from
the schema API.

The classification is **descriptive metadata only**. It never changes who may
read a schema or its objects; that stays with schema RBAC. The per-object
confidentiality tier is a separate feature.

## The two fields

A schema accepts two optional fields beside `title` and `description`:

```json
{
  "title": "Meldingen",
  "classification": "internal",
  "catalogue": {
    "maintainer": { "organisation": "Gemeente Voorbeeld", "department": "Openbare ruimte" },
    "contact": { "name": "Team data", "email": "data@voorbeeld.nl" },
    "sourceSystem": "Meldingen app",
    "updateFrequency": "daily",
    "documentationUrl": "https://voorbeeld.nl/docs/meldingen",
    "labels": ["meldingen", "openbare-ruimte"]
  }
}
```

Both are nullable. A schema with neither set renders and exports exactly as
before.

### `classification`

One of a closed vocabulary, the four values of the Objects API:

| Value | Meaning |
| --- | --- |
| `open` | public data |
| `internal` | for the organisation |
| `confidential` | restricted |
| `strictly-confidential` | tightly restricted |

Any other value is refused on save with a 400 that names `classification`.

### `catalogue`

| Field | Shape |
| --- | --- |
| `maintainer` | object: `organisation`, `department` |
| `contact` | object: `name`, `email` (checked as an e-mail address) |
| `sourceSystem` | string |
| `updateFrequency` | one of `realtime`, `daily`, `weekly`, `monthly`, `yearly`, `irregular` |
| `documentationUrl` | string |
| `labels` | list of strings |

An unknown `updateFrequency` or a malformed `contact.email` is refused with a
400 that names the field.

## Reading

- `GET /api/schemas/{id}` returns both fields.
- `GET /api/schemas?classification=internal` lists only the schemas classified
  `internal` that the caller may already list. An unknown value is a 400.
- An **anonymous** caller never sees `catalogue.contact`: the contact person is
  personal data, so it is omitted from the list, the detail, the generated
  OpenAPI document and the JSON-LD context. The other catalogue fields and the
  classification stay.

## Exchange

A register export, the generated OpenAPI document and the JSON-LD output carry
both fields, and an import restores them:

- OpenAPI: each schema component gains `x-openregister-classification` and
  `x-openregister-catalogue` (without the contact).
- JSON-LD: the context maps `maintainer` to `dcat:contactPoint` and
  `updateFrequency` to `dct:accrualPeriodicity`, so a DCAT harvester reads them.
  A schema property of the same name keeps its own term.
