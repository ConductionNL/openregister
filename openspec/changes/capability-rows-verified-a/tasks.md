# Tasks: capability-rows-verified-a

## 1. Field editors per type

- [ ] 1.1 Pin the date, switch and format scenarios in `src/services/propertyEditor.spec.js` (`editorFor` returns `date` for a `date-time` string and `switch` for a boolean) and in a PHPUnit test that saves `email: "not-an-address"` through `lib/Service/Object/ValidateObject.php` and reads the field name in the refusal.

## 2. A public form creates a record

- [ ] 2.1 Serve a form declared `access: anonymous` on a public page that needs no account, render it with `CnFormPage`, and submit it through the journey run API so a record is created in the target schema. Verify: a Playwright test opens the form link in a logged-out browser, submits it, and finds the record as an administrator.

## 3. Searches that found nothing

- [ ] 3.1 Pin the zero result mark: a test that records one search with results and one without, then reads `non_empty_searches` from `lib/Db/SearchTrailMapper.php` statistics, and a component test that `src/views/logs/SearchTrailIndex.vue` gives the empty search the `failed` row class.
