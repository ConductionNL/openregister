# Proposal: listed configuration objects carry identity, not data

## Why

The live pass of 5 Oct (defect O6) imported three objects under a configuration's `components.objects`, each with a top-level `uuid` and `slug` as seed data carries them. MagicMapper logged "Discarding 2 properties the schema does not declare: slug, notInSchema" and every object got a random uuid. #4315 removed the seed keys only on the `x-openregister.seedData` path; `components.objects` neither stripped them nor used the uuid. learniq_register.json lists objects with a top-level slug, so every import of it logs the discard.

## What changes

- The `components.objects` import takes the object's uuid from `@self.uuid`, else the top-level `uuid`, and creates the object under it.
- It removes the top-level `uuid` and `slug` from the data unless the schema declares them, the same strip the seed path applies.

## Impact

- `lib/Service/Configuration/ImportHandler.php` (`importFromJson()` object loop). Existing objects are found by slug as before; only a new object's uuid changes, from random to the one the configuration names.
