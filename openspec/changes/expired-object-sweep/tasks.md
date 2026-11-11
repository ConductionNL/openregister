# Tasks: expired-object-sweep

Kind: code. Size M. Row: portaliq `ops-submission-retention`.

## 1. Schema key

- [ ] 1.1 Validate `x-openregister.expiry` (design D1) on schema save. Verify: `tests/Unit/Service/SchemaExpiryValidationTest.php` covers a good key, an unknown action and an unknown kept property.

## 2. Save path

- [ ] 2.1 Store `@self.expires` on create and update for opted-in schemas in `lib/Service/Object/SaveObject.php`, behind the update permission. Verify: `tests/Unit/Service/Object/SaveObjectExpiresTest.php` asserts it is stored for an opted-in schema and ignored for another.

## 3. Sweep

- [ ] 3.1 Add `lib/Service/Retention/ExpiredObjectSweeper.php` with the steps of design D3 and the audit entries `expiry.deleted` and `expiry.anonymised`. Verify: `tests/Db/ExpiredObjectSweeperTest.php` covers delete, anonymise with files, legal hold, a schema that did not opt in, and the batch limit.
- [ ] 3.2 Add `lib/BackgroundJob/ExpiredObjectSweepJob.php` (daily, app config keys of D3) and register it in `appinfo/info.xml`. Verify: `tests/Unit/BackgroundJob/ExpiredObjectSweepJobTest.php`.

## 4. Dry run

- [ ] 4.1 Add `lib/Command/ExpirySweepCommand.php` (`occ openregister:expiry:sweep [--dry-run]`). Verify: `tests/Unit/Command/ExpirySweepCommandTest.php` asserts no write in dry run.

## 5. Docs

- [ ] 5.1 Document the schema key, the sweep and the dry run in `docs/features/expired-object-sweep.md`, and say how it differs from archival destruction. Verify: `npm run build` in `docs/` succeeds.
