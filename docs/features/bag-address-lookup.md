# BAG address lookup

Find the address at a postcode and house number, for the whole country, fast
and without a login. A form asks for postcode and number; OpenRegister answers
the street and town, so a resident does not type them.

The addresses live in the `bag` register as `nummeraanduiding` objects. A
development instance has the mock seed of the BAG mock register; a production
instance loads the national BAG address set into the same register.

## Loading the national set

```bash
# From a file on the server
occ openregister:bag:load --source=/srv/bag/nummeraanduiding.csv

# From a URL, such as an internal mirror of the PDOK download
occ openregister:bag:load --source=https://mirror.example.nl/bag/nummeraanduiding.ndjson

# From the configured source (bag_bulk_url)
occ openregister:bag:load
```

The command prints what the load did:

```text
Created 9123456, updated 0, withdrawn 0, unchanged 0, invalid 12, failed 0.
```

A load is an upsert keyed on the BAG `identificatie`:

- a new address is created, an address whose street, town, number, letter,
  addition, postcode, status or addressable object changed is updated, and an
  address that did not change is not written at all, so a monthly refresh
  touches only what changed;
- a withdrawn address keeps its BAG status `Naamgeving ingetrokken`; the
  lookup skips it;
- a row that is no address (no 16-digit `identificatie`, a postcode or house
  number that is not one) is counted as invalid and skipped;
- rows are saved in chunks of 5,000 without an audit trail entry per object.
  The load writes one audit line, action `bag.load`, with the counts and the
  source (a URL without its query or credentials).

### Extract formats

The loader reads a CSV with a header row (comma or semicolon), a GeoJSON
FeatureCollection or JSON list as the PDOK OGC API answers it, or
newline-delimited JSON. Columns may carry the schema's names or the PDOK
spelling:

| Schema property | Also accepted |
| --- | --- |
| `identificatie` | `nummeraanduiding`, `nummeraanduiding_identificatie` |
| `huisnummer` | |
| `huisletter` | |
| `huisnummertoevoeging` | `toevoeging` |
| `postcode` | |
| `status` | (absent: `Naamgeving uitgegeven`) |
| `typeAdresseerbaarObject` | `type_adresseerbaar_object` |
| `openbareRuimteNaam` | `openbare_ruimte_naam`, `straat` |
| `woonplaatsNaam` | `woonplaats_naam`, `woonplaats` |
| `adresseerbaarObject` | `adresseerbaar_object_identificatie`, `verblijfsobject` |

## Configuration

| App config key | Default | What it does |
| --- | --- | --- |
| `bag_bulk_url` | empty | The extract the command loads without `--source`, and the one the monthly refresh loads. A file path or an http(s) URL, so an air-gapped instance can name an internal mirror. |
| `bag_bulk_enabled` | `false` | Switches the monthly refresh (`BagRefreshJob`) on. While it is off, the job does nothing. |

```bash
occ config:app:set openregister bag_bulk_url --value=https://mirror.example.nl/bag/nummeraanduiding.ndjson
occ config:app:set openregister bag_bulk_enabled --value=true --type=boolean
```

## How addresses are stored

| Property | Stored as |
| --- | --- |
| `postcode` | four digits not starting with 0, then two capitals, no space: `2611AB` |
| `huisnummer` | an integer from 1 to 99999 |
| `huisletter` | one capital, or absent |
| `huisnummertoevoeging` | up to four capitals or digits, or absent |

The `nummeraanduiding` schema declares
`"x-openregister-indexes": [["postcode", "huisnummer"]]`, so its magic table
gets one composite index over postcode and house number and a lookup is one
indexed query. Any schema can declare such an index: each entry is a list of at
least two of its properties, indexed together in that order.

## The lookup endpoint

```http
GET /index.php/apps/openregister/api/bag/addresses?postcode=2611%20ab&huisnummer=12
```

| Parameter | Required | Notes |
| --- | --- | --- |
| `postcode` | yes | Any case, with or without a space. |
| `huisnummer` | yes | |
| `huisletter` | no | Narrows to one letter. |
| `toevoeging` | no | Narrows to one addition. |

```json
{ "results": [ { "nummeraanduiding": "0599200000001234", "verblijfsobject": "0599010000005678",
  "straat": "Lindelaan", "huisnummer": 12, "huisletter": null, "toevoeging": null,
  "postcode": "2611AB", "plaats": "Zuiddrecht" } ], "total": 1 }
```

- With only postcode and number, every letter and addition at that number is
  returned, so a form can ask which one.
- No match answers `404` with an empty list; input that is no postcode or
  number answers `400` (`invalid_address`); a register that cannot be read
  answers `503` without its error.
- The endpoint is public and needs no CSRF token. It is throttled at 60
  lookups a minute per client address.

From PHP, an app calls the same lookup through
`OCA\OpenRegister\Service\Bag\BagAddressLookup::find(postcode, number, letter, addition)`,
which answers the same `results` and `total` (an empty list rather than a 404).
