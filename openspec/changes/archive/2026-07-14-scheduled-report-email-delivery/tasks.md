# Tasks — Scheduled Report Email Delivery

## 1. Storage: entity fields + migration
- [x] 1.1 `lib/Db/ScheduledReport.php` — `deliveryMode`/`recipients` fields, `addType()` entries, `getRecipientsArray()` accessor (mirrors `getFiltersArray()`), `jsonSerialize()` additions.
- [x] 1.2 `lib/Migration/Version1Date20260714000000.php` — idempotent `changeSchema()` ALTER adding `delivery_mode` (default `'files'`) and `recipients` to `openregister_scheduled_reports`. Does not edit the shipped `Version1Date20260713000000`.

## 2. Service: validation + email delivery leg
- [x] 2.1 `ScheduledReportService::ALLOWED_DELIVERY_MODES`, `MAX_RECIPIENTS`, `MAX_EMAIL_ATTACHMENT_BYTES` constants.
- [x] 2.2 `validate()` — `deliveryMode` enum check, `validateRecipients()` (array/cap/per-entry email format), `normalizeRecipients()` (trim/dedupe) applied before storage in both `create()` and `update()`.
- [x] 2.3 `runOne()` restructured into two independent delivery legs (Files unchanged, new email leg) plus `finalizeOutcome()` to determine `success`/`email_failed`/`failed` without an if/elseif/else chain.
- [x] 2.4 `runExport()` (renamed from `exportBytes()`) also derives a best-effort row count per format (`countCsvRows()`, `countSpreadsheetRows()`, `bestEffortRowCount()` for pdf) without changing the original per-format `ExportService` call surface.
- [x] 2.5 `deliverToEmail()` — `OCP\Mail\IMailer` template + attachment-under-cap / oversize-Files-fallback-with-link, isolated failure reason returned (never throws).
- [x] 2.6 `resolveRecipients()` — explicit list or default-to-owner-email, empty when neither resolves.
- [x] 2.7 `buildEmailBodyLines()`, `resolveFromAddress()` (mirrors `FlowActionService::runEmail()`'s `mail_from_address`/`mail_domain` config pattern), `mimeTypeFor()`, `humanFileSize()`.

## 3. Notifier
- [x] 3.1 `lib/Notification/Notifier.php` — `prepareScheduledReportDelivered()` parameterized with `mode`/`emailFailureReason`; no new subject added.

## 4. Controller/API
- [x] 4.1 No controller code change needed — `create()`/`update()` already forward the full payload to the service, which now validates the two new fields; `jsonSerialize()` (already returned by `index`/`show`) carries them.

## 5. Tests
- [x] 5.1 `tests/Unit/Service/ScheduledReportServiceRecipientsValidationTest.php` — deliveryMode enum, recipients valid/invalid/dedup/trim/cap/over-cap, update preserves recipients when not sent.
- [x] 5.2 `tests/Unit/Service/ScheduledReportServiceEmailDeliveryTest.php` — mode matrix (files/email/both), attachment-vs-oversize-fallback (including "both" not double-writing Files), `email_failed` isolation (Files still delivered) vs email-only hard `failed`, default-to-owner-email vs explicit recipients, owner-without-email behaviour under both `email`-only and `both` modes.
- [x] 5.3 `tests/Unit/Controller/ScheduledReportsControllerTest.php` — create forwards `deliveryMode`/`recipients` through and returns them; 422 propagation for delivery-field validation failures on create/update.
- [x] 5.4 Existing `ScheduledReportServiceDueTest`/`ScheduledReportServiceValidationTest`/`ScheduledReportServiceRunOneTest`/`ScheduledReportJobTest` updated for the two new constructor dependencies (`IMailer`, `IConfig`) and verified still green — default `deliveryMode: files` preserves their original assertions unchanged.
- [x] 5.5 Full existing PHPUnit suite run; any failures verified pre-existing/unrelated by file path.

## 6. Quality gates
- [x] 6.1 SPDX docblocks on every new/changed PHP file.
- [x] 6.2 PHPCS clean on changed `lib/` files (the project's PHPCS scope is `lib/` only; `tests/` is not gated).
- [x] 6.3 PHPMD clean on changed files via targeted `@SuppressWarnings` in the established pattern (`TooManyMethods`, `ExcessiveParameterList`) plus a `runOne()`/`finalizeOutcome()` extraction that resolved `CyclomaticComplexity`/`ElseExpression`/`LongVariable` without any suppression.
- [x] 6.4 PHPStan clean — `phpstan-baseline.neon`'s existing `ScheduledReportService.php` ignore-count entries bumped to reflect the new `_rbac`/`_multitenancy` named-argument call sites (`RegisterMapper`/`SchemaMapper::find()`), same established baseline-count pattern.
- [x] 6.5 Psalm: 0 errors on changed files.
- [x] 6.6 No new composer dependencies (`composer.lock` unchanged) — `OCP\Mail\IMailer` is core Nextcloud API, already used elsewhere in this app.
