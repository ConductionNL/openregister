# Design: connections-daily-report-mail

Read at openregister development c53dd0685c. integriq's schemas read at integriq development 23c672699d.

## D-1: a report kind, on the scheduled report that already mails

`ScheduledReport` (`lib/Db/ScheduledReport.php`) holds a register, a schema, filters, a format, a schedule, a delivery mode and recipients; `ScheduledReportService` runs it hourly-due as its owner (`runOne()`, `lib/Service/ScheduledReportService.php:601-680`) and mails it (`deliverToEmail()`, `:1065-1134`). A connection report is the same life cycle with different content, so it is a new kind rather than a new job.

- A migration adds `kind` (string 32, not null, default `export`) and `options` (JSON, nullable) to the scheduled report table.
- `validate()` (`:393-462`) accepts `kind` `export` or `connection-health`. For `connection-health` it requires no register or schema, forces `deliveryMode` `email`, and takes `options.onlyWhenAttention` (boolean, default false) and `options.lookbackHours` (default 24, 1 to 168).
- `runExport()` (`:739-767`) dispatches on the kind: `export` runs as today, `connection-health` calls `ConnectionHealthReportBuilder::build()` and returns `{bytes, rowCount, attention, html}`.
- `ScheduledReportsController` refuses `kind: connection-health` from a non-administrator with 403, before `create()` or `update()`. Existing reports read as kind `export` and nothing changes for them.

## D-2: what the builder reads

`lib/Service/Connection/ConnectionHealthReportBuilder.php` reads Open Register objects in register `integriq` through `ObjectService`, as the report's owner (`runOne()` already sets the owner in the session). The slugs are integriq's data contract from hydra change `connection-registry` and integriq's register files:

| schema | fields used |
|---|---|
| `app_connection` | `app`, `key`, `title`, `status`, `statusMessage`, `checkedAt`, `settingsUrl` |
| `synchronization_run` | `synchronizationId`, `status`, `startedAt`, `finishedAt`, `found`, `created`, `updated`, `deleted`, `invalid`, `message` |
| `synchronization` | `name`, `sourceId` |
| `source` | `name` |

Reads are bounded: all `app_connection` rows (they are one per declared connection, tens per app) with a hard cap of 1,000; `synchronization_run` rows with `startedAt` within the lookback, capped at 5,000 and sorted newest first; the synchronizations and sources those runs name, fetched by id in one call each. A cap that is hit is stated in the mail ("5,000 runs shown; more ran").

When register `integriq` does not exist, the builder throws a named `ConnectionRegistryMissingException`. The run is marked failed with "integriq is not installed, so there are no connection rows to report on", through the existing failure path (`runOne()` catch blocks, `:658-677`).

## D-3: what needs attention

A row needs attention when:

- an `app_connection` has status `error`, `unavailable` or `limited`, or `simulated` on a connection that is not `reportedOnly` (a mock answering where a real system was expected);
- a synchronization had at least one `failed` run in the lookback;
- a synchronization had runs in the previous lookback window but none in this one (it stopped running);
- a run has been `running` for more than 6 hours (stuck).

`attention` is the count of such rows. With `onlyWhenAttention` and `attention` 0, the run is recorded as `success` with "Nothing needed attention; no mail sent", and no mail goes out.

## D-4: the mail

`buildEmailBodyLines()` (`:1176-1201`) today writes a register and a row count. For `connection-health` a new `buildConnectionReportBody()` fills the same `openregister.scheduledReportDelivery` e-mail template that `deliverToEmail()` already creates:

- **Subject:** "Connection report {date}: {n} need attention", or "Connection report {date}: all clear".
- **Needs attention:** one line per row from D-3: app, connection or source, status or failure, message, since when, and the settings link or integriq's synchronization page.
- **Runs per source system:** source, synchronization, runs, succeeded, failed, found, created, updated, invalid, last finished.
- **All connections:** grouped by app, title and status.
- **Attachment:** `connection-report-{yyyy-MM-dd}.csv`, UTF-8 with byte order mark, with a `section` column and the rows of all three parts. Cells are formula-safe (a leading `=`, `+`, `-` or `@` is prefixed with a quote).

Text and status labels are English, like the status messages the rows carry (hydra `connection-registry` keeps stored messages untranslated); the fixed sentences go through `IL10N` in the owner's language.

## D-5: recipients

`resolveRecipients()` (`:1149-1166`) returns the configured addresses or the owner. It learns one token: `@admins` expands to the e-mail addresses of the members of Nextcloud's `admin` group, read through `IGroupManager`. A member without an address is skipped and named in the run record. The expanded list shares the existing cap of 20 (`MAX_RECIPIENTS`, `:108`); beyond it the first 20 by user id receive the mail and the run record says how many were left out. `validateRecipients()` (`:478`) accepts the token.

## D-6: switching it on

The Connections page (`src/manifest.json`, page `connections`, `headerActions` beside `add-integration`) gets a header action `connection-report`, label "Daily report by e-mail", handler `openConnectionReportDialog` in `src/customComponents.js` (next to `openIntegriqConnections`, `:33`). The handler sets the dialog `connectionReport` in the navigation store, and `src/dialogs/Dialogs.vue` renders the new `src/dialogs/connections/ConnectionReportDialog.vue` (NcDialog). The dialog:

- shows whether a connection report schedule exists for the current administrator and lets them create, change or switch it off (`POST`, `PUT`, `DELETE /api/scheduled-reports`);
- picks recipients: "All administrators" (the `@admins` token) and extra addresses;
- picks the hour (the schedule is `daily`) and "Only when something needs attention";
- has "Send a test now", which calls the existing `POST /api/scheduled-reports/{id}/run-now`.

## Declarative-vs-imperative decision

Imperative. The report aggregates across two schemas of another app's register with rules (D-3) that are about operations, not about any one object's data. It rides the existing scheduled report life cycle, which is itself declared data (a `ScheduledReport` row with a schedule), so what is scheduled stays declarative and only the content builder is code.

## Risks

- **Security.** The kind is administrator-only, the read runs as the owner, and the mail goes to administrators. The mail carries statuses and counts, never a source's credentials: `source` is read for its `name` only.
- **Coupling.** The builder depends on integriq's slugs and field names. They are the published contract of the connection registry, and a missing register fails the run with a named reason instead of mailing an empty "all clear".
- **Performance.** Bounded reads (D-2) once a day; no scan of every magic table.
- **Mail volume.** At most one mail a day per schedule, 20 recipients at most, and none on quiet days when asked.
