# Tasks: connections-daily-report-mail

## 1. Report kind

- [ ] 1.1 Migration adding `kind` (default `export`) and `options` to the scheduled report table; `ScheduledReport` accessors; `validate()` rules for `connection-health` (no register or schema, e-mail delivery, `onlyWhenAttention`, `lookbackHours` 1 to 168); a 403 in `ScheduledReportsController` for a non-administrator creating or changing that kind. Verify: `tests/Unit/Service/ScheduledReportServiceKindTest.php`; an existing report without `kind` still runs its export; a Newman request asserts 403 for a non-administrator.

## 2. Builder

- [ ] 2.1 Add `lib/Service/Connection/ConnectionHealthReportBuilder.php`: bounded reads of `app_connection`, `synchronization_run`, `synchronization` and `source` in register `integriq` (design D-2), the attention rules (D-3), the three-part body and the formula-safe CSV (D-4), and `ConnectionRegistryMissingException` without integriq. Verify: `tests/Unit/Service/Connection/ConnectionHealthReportBuilderTest.php` covers each attention rule, the caps with their stated truncation, a `source` credential never reaching the output, and the missing-register failure.
- [ ] 2.2 Dispatch on kind in `runExport()`, add `buildConnectionReportBody()` to the existing mail template, and record a quiet day under `onlyWhenAttention` as success without a mail. Verify: `tests/Unit/Service/ScheduledReportConnectionHealthRunTest.php` with a mocked `IMailer` asserts one mail with the attachment on an attention day and none on a quiet day.

## 3. Recipients

- [ ] 3.1 Teach `resolveRecipients()` and `validateRecipients()` the `@admins` token: members of the `admin` group with an address, capped at 20, with skipped members and overflow named in the run record. Verify: `tests/Unit/Service/ScheduledReportRecipientsTest.php`.

## 4. Page

- [ ] 4.1 Add the `connection-report` header action to the `connections` page in `src/manifest.json`, the `openConnectionReportDialog` handler in `src/customComponents.js`, and `src/dialogs/connections/ConnectionReportDialog.vue` rendered from `src/dialogs/Dialogs.vue`, with create, change, switch off and "Send a test now". Verify: `src/tests/connections-page.spec.js` extended for the new action and handler; `src/dialogs/connections/ConnectionReportDialog.spec.js` for the recipients choice and the quiet-day option.

## 5. Docs and end-to-end test

- [ ] 5.1 Document the report, its attention rules, recipients and the quiet-day option in a new `docs/features/connection-report.md`, linked from `docs/sidebars.js`. Verify: `npm run build` in `docs/` succeeds.
- [ ] 5.2 Add `tests/e2e/ci/connection-report.spec.ts`: with integriq installed in the CI instance, an administrator opens the Connections page, switches the daily report on for all administrators, presses "Send a test now", and the run record shows a delivered mail with the attention count. Verify: the spec runs green in the Playwright CI project; the mail itself is asserted through the run record, since CI has no mailbox.

## Acceptance

- An existing scheduled export behaves exactly as before.
- No mail says "all clear" when the connection rows could not be read.
