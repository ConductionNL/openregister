# Design — Scheduled Report Email Delivery

## New columns on `openregister_scheduled_reports`
- `delivery_mode` (string 16, not null, default `'files'`) — `files` | `email` | `both`. Defaulting to `files` means every row created before this migration keeps its exact prior (Files-only) behaviour with zero data migration.
- `recipients` (text, nullable) — opaque JSON array of email address strings. Null/empty means "default to the owner's own Nextcloud email address, resolved at run time", not "no recipients configured yet" — the same "opaque JSON, decoded via a `getXArray()` accessor" convention `filters` already uses on this entity.

Migration `Version1Date20260714000000` follows the established `hasColumn()`-guarded, idempotent ALTER-TABLE pattern (see `Version1Date20260712130000` for the precedent in this app) — it is a separate file, never an edit to the already-shipped `Version1Date20260713000000`.

## Validation (`ScheduledReportService::validate()`)
- `deliveryMode` must be one of `ALLOWED_DELIVERY_MODES = ['files', 'email', 'both']`, defaulting to `files` when absent (so existing API clients that don't send it are unaffected).
- `recipients` must be an array, capped at `MAX_RECIPIENTS = 20`, each entry a string that passes `filter_var($trimmed, FILTER_VALIDATE_EMAIL)` after trimming (validation trims the same way storage does, so incidental whitespace never fails validation only to pass moments later once normalized). Invalid payloads are rejected with 422 at create/update time — the same "reject at config time" discipline already applied to `format`/`scheduleType`.
- On persist, `recipients` is normalized (trimmed, de-duplicated, empty entries dropped) before JSON-encoding, mirroring `filters`' `json_encode(..., JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` convention.

## `runOne()`: two independent delivery legs
`runOne()` now runs the Files leg (unchanged `deliverToFiles()`, gated on `deliveryMode` being `files`/`both`) and the email leg (new `deliverToEmail()`, gated on `deliveryMode` being `email`/`both`) as two independent steps, then determines the final outcome from both results via the extracted `finalizeOutcome()` helper (kept separate from `runOne()` to avoid an if/elseif/else chain and keep `runOne()`'s own cyclomatic complexity down):

| Files leg (if requested) | Email leg (if requested) | `lastStatus` | Notes |
|---|---|---|---|
| succeeds / not requested | succeeds / not requested | `success` | Existing behaviour for `files`-only reports, unchanged. |
| succeeds | fails | `email_failed` | New: Files delivery is not undone or hidden; `lastError` carries the email failure reason. |
| not requested (mode `email`) | fails | `failed` | Nothing was delivered — matches existing single-channel all-or-nothing semantics. |
| fails (path traversal, Files folder unavailable, etc.) | (not reached — export/Files exceptions still propagate to the outer catch, unchanged) | `failed` | Existing behaviour, unchanged. |

`ExportTooLargeException`/other `\Throwable` from export generation itself are still caught by `runOne()`'s outer try/catch exactly as before — the two-leg logic only runs once export bytes exist.

## Row count for the email body
The email body states register/schema/schedule/row count. Row counting must never turn an otherwise-successful export into a failure and must not double-fetch data for the common formats:
- `csv`: the row count is parsed directly from the already-produced CSV bytes (non-empty lines minus the header row) — zero extra fetches, and `ExportService::exportToCsv()` is still called exactly as before (preserving the original test surface).
- `excel`: `ExportService::exportToExcel()` already returns the `Spreadsheet` object before it's serialized to `.xlsx` bytes; the row count sums `getHighestRow() - 1` across every sheet from that same object.
- `pdf`: `ExportService::exportToPdf()` returns only rendered bytes, not a count. A second, bounded (≤ `ExportService::MAX_PDF_EXPORT_ROWS`, since the guard inside `exportToPdf()` already passed) `exportToExcel()` call derives the count; any failure in that secondary call is caught and swallowed (`bestEffortRowCount()` degrades to `0`) — it can never fail an otherwise-successful pdf export.

## Recipient resolution (`resolveRecipients()`)
1. If the report has a non-empty `recipients` list, use it verbatim (each already validated at config time).
2. Otherwise, resolve the impersonated owner's `IUser::getEMailAddress()`. An owner with no email on file and no explicit recipients resolves to an empty recipient set — `deliverToEmail()` returns immediately with a "no valid recipient" failure reason (participates in the outcome table above like any other email-leg failure).

## Attachment cap and oversize fallback
`MAX_EMAIL_ATTACHMENT_BYTES = 10485760` (~10MB) — a conservative cap under typical SMTP relay message-size limits. When export bytes exceed the cap:
- If the Files leg already ran this pass (`deliveryMode: both`), the export is already safely in Files — the email just omits the attachment and adds a body note with the folder/filename.
- If the Files leg did **not** run this pass (`deliveryMode: email` only), `deliverToEmail()` calls the same `deliverToFiles()` used by the Files leg as a fallback before sending, so the data is never silently dropped, then adds the same body note.

Either way, exactly one `deliverToFiles()` call happens for a given run regardless of mode/size combination — never a double-write.

## Notifier
`scheduled_report_delivered` is parameterized with `mode` (`files`/`email`/`both`) and an optional `emailFailureReason`, rather than adding a new subject:
- `mode: files` — unchanged wording ("...saved to `<folder><filename>`").
- `mode: email` — "...was emailed to its recipients." (no Files action link).
- `mode: both` — combines both phrases; keeps the "Open Files" action.
- Any non-null `emailFailureReason` appends a "Note: email delivery failed (`<reason>`)." sentence, covering the `email_failed` case without a third subject.

`scheduled_report_failed` is unchanged — used identically whether the failure originated in export generation, the Files leg, or an email-only leg.

## Out of scope (unchanged from `scheduled-report-jobs`)
Report templates/branding, cron expressions, retention-period cleanup, and n8n-workflow delivery remain open under `rapportage-bi-export`. This change only adds a second delivery channel to the existing scheduling/export/notification pipeline.
