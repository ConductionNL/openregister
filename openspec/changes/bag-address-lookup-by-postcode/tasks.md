# Tasks: bag-address-lookup-by-postcode

Kind: code. Size M. Row: portaliq `int-address-lookup`.

## 1. Schema

- [ ] 1.1 Normalise `postcode`, `huisnummer`, `huisletter`, `huisnummertoevoeging` in `lib/Settings/bag_register.json` (patterns and types of design D2) and declare the composite index of D3. Normalise the mock seed to match. Verify: re-import shows no `PARTIAL IMPORT` line; `tests/Db/BagIndexTest.php` asserts the index exists on the magic table.

## 2. Loader

- [ ] 2.1 Add `lib/Service/Bag/BagBulkLoader.php` (upsert in chunks of 5,000 keyed on `identificatie`, withdrawn status, one audit line) and `lib/Command/BagLoadCommand.php` (`occ openregister:bag:load [--source=]`). Verify: `tests/Db/BagBulkLoaderTest.php` with a 10,000-row sample extract under `tests/fixtures/bag/`.
- [ ] 2.2 Add `lib/BackgroundJob/BagRefreshJob.php` (monthly, off by default, app config `bag_bulk_enabled`, `bag_bulk_url`). Verify: `tests/Unit/BackgroundJob/BagRefreshJobTest.php` asserts it does nothing while disabled.

## 3. Lookup

- [ ] 3.1 Add `lib/Service/Bag/BagAddressLookup.php` with `find()` per design D4, skipping withdrawn addresses. Verify: `tests/Unit/Service/Bag/BagAddressLookupTest.php` covers normalised input, several additions, and no match.
- [ ] 3.2 Add `lib/Controller/BagAddressController.php` and the route `GET /api/bag/addresses`, public, no CSRF, throttled. Verify: gate `route-auth` passes; `tests/Integration/bag-address-lookup.postman_collection.json` covers a hit, a 404 and the throttle.

## 4. Docs

- [ ] 4.1 Document the load, the config keys and the endpoint in `docs/features/bag-address-lookup.md`. Verify: `npm run build` in `docs/` succeeds.
