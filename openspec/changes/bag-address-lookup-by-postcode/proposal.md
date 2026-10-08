---
kind: code
---

# Proposal: bag-address-lookup-by-postcode

## Summary

Make the BAG register answer "which address is postcode 2611 AB, number 12" for the whole country, fast and without a login. Today `lib/Settings/bag_register.json` is a mock with about thirty `nummeraanduiding` records. This change adds a loader for the national BAG address set, normalises and indexes postcode and house number, and gives one lookup an app can call through ObjectService or over a public endpoint.

Rows covered: portaliq `int-address-lookup` (decision 104). This is OpenRegister's half of portaliq's `data-lookups-and-checks-in-forms` (merged in portaliq #1387).

## Why

Portaliq's change says: "Address: `GET /api/intake/address?postcode=&number=&letter=&addition=` answers street and town from OpenRegister's BAG register through ObjectService, or 404." And: "OpenRegister owns BAG, BRP and the reference list data (`lib/Settings/bag_register.json`, `BrpPersonProvider`). Portaliq queries them; it stores none of it (ADR-022)." The FormulierVelden board draws it: "U vult uw postcode en huisnummer in. Wij zoeken de straat en plaats erbij."

Open Formulieren 4.0.1 does it with its `addressNL` component over the Kadaster BAG API (`src/openforms/formio/components/custom.py:681`, `src/openforms/contrib/kadaster/`).

What OpenRegister has:

- `bag_register.json` with `nummeraanduiding` (`postcode`, `huisnummer`, `huisletter`, `huisnummertoevoeging`, `openbareRuimteNaam`, `woonplaatsNaam`), `verblijfsobject` and `pand`, seeded from PDOK as mock data (`mock-registers` spec, "BAG Mock Register").
- No loader for the full set, no normalised postcode, and no index that makes an exact postcode plus number filter cheap over nine million rows.
- Integriq's PDOK adapter writes looked-up addresses into an `addresses` register (integriq `pdok-adapter`, "Write-Through to OR Addresses Register"). That is a cache of single lookups for signed-in users, not a register a public form can query.

## What changes

- **A national load.** An admin command and a monthly background job load the BAG `nummeraanduiding` set (with street and town names) from the PDOK BAG bulk download into the `bag` register, as an upsert keyed on `identificatie`. Withdrawn addresses get status `ingetrokken` and are skipped by the lookup. The mock seed stays for development instances.
- **A normalised key.** `postcode` is stored as four digits and two capitals without a space; `huisnummer` is an integer; `huisletter` is one capital; `huisnummertoevoeging` is capitals. A composite index on postcode and house number backs the lookup.
- **One lookup.** `BagAddressLookup::find(postcode, huisnummer, huisletter?, toevoeging?)` answers zero or more matches with street, town, nummeraanduiding id and the verblijfsobject id. `GET /api/bag/addresses?postcode=&huisnummer=&huisletter=&toevoeging=` exposes it publicly, throttled per client.
- **Several matches.** With only postcode and number, every letter and addition at that number is returned, so a form can ask which one.

## Out of scope

- Field rendering, the blur behaviour and the editable street: portaliq.
- Buildings, surfaces and geometry beyond what `verblijfsobject` and `pand` already hold.
- The BRK (Kadaster ownership): integriq's service fetch.

## Impact

- Specs: new capability `bag-address-lookup`.
- New: `lib/Service/Bag/BagAddressLookup.php`, `lib/Service/Bag/BagBulkLoader.php`, `lib/Command/BagLoadCommand.php`, `lib/BackgroundJob/BagRefreshJob.php`, `lib/Controller/BagAddressController.php`.
- Changed: `lib/Settings/bag_register.json` (normalisation, index hint, `status` filter), `appinfo/routes.php`, `appinfo/info.xml` (command and job).

## Cross-project dependencies

- portaliq `data-lookups-and-checks-in-forms` calls the lookup from `GET /api/intake/address`.
