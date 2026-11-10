# Design: bag-address-lookup-by-postcode

No board of OpenRegister's own. The resident-facing block is portaliq's FormulierVelden board (canvas `5NkFW28vZUUij43xzxHg5a`): Postcode, Huisnummer, Toevoeging, then the found street and town.

## D1. Where the data comes from

PDOK publishes the BAG as a national download (the LV BAG extract and the BAG OGC API Features). The loader reads the `nummeraanduiding` collection joined with `openbareruimte` and `woonplaats` names, which is what the existing schema already holds. The source URL is an app config key (`bag_bulk_url`), so an air-gapped instance can point at an internal mirror. A load is an upsert keyed on `identificatie`, in chunks of 5,000 through the bulk save path, without audit trail entries per row (the load itself writes one audit line with counts).

**Alternative considered.** Ask PDOK per lookup through integriq. Rejected for the public form path: it makes every anonymous keystroke an outside call, and portaliq's change asks OpenRegister's register explicitly.

## D2. Normalisation

| Property | Stored as |
| --- | --- |
| `postcode` | `^[1-9][0-9]{3}[A-Z]{2}$`, spaces removed, upper case |
| `huisnummer` | integer |
| `huisletter` | one upper case letter or null |
| `huisnummertoevoeging` | up to four upper case characters or null |

The lookup normalises its input the same way, so "2611 ab" finds "2611AB".

## D3. The index

The `bag` register's `nummeraanduiding` schema declares `x-openregister.indexes: [["postcode", "huisnummer"]]`; the magic table gets that composite index on migration. A lookup is one indexed query.

## D4. The answer

```json
{ "results": [ { "nummeraanduiding": "0599200000001234", "verblijfsobject": "0599010000005678",
  "straat": "Lindelaan", "huisnummer": 12, "huisletter": null, "toevoeging": null,
  "postcode": "2611AB", "plaats": "Zuiddrecht" } ], "total": 1 }
```

No match answers 200 with an empty list in the service and 404 on the endpoint. The endpoint is `#[PublicPage]`, `#[NoCSRFRequired]`, and throttled at 60 requests per minute per client address.
