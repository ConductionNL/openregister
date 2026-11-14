# Connection report by e-mail

A daily e-mail that says which outside connections need attention, so an administrator
does not have to open the Connections page to find out that a synchronisation stopped.

The report reads the connection rows and synchronisation runs that integriq keeps in its
register `integriq`. It needs integriq installed. Without integriq the run fails with
"integriq is not installed, so there are no connection rows to report on" instead of
mailing an empty all clear.

## How it is scheduled

The report is a scheduled report of kind `connection-health`. It uses the same life cycle
as a scheduled export: a schedule, a delivery by e-mail, a run record per run, and
**Run now**. Only an administrator can create or change one. A request from anyone else
gets a 403.

```http
POST /index.php/apps/openregister/api/scheduled-reports
Content-Type: application/json

{
  "name": "Daily connection report",
  "kind": "connection-health",
  "scheduleType": "daily",
  "scheduleHour": 7,
  "deliveryMode": "email",
  "recipients": ["@admins"],
  "options": {"onlyWhenAttention": true, "lookbackHours": 24}
}
```

- `kind` must be `connection-health`. The report takes no register or schema, and it is
  always a CSV sent by e-mail.
- `scheduleType` and `scheduleHour` work as for any scheduled report.
- `options.lookbackHours` is how far back the report looks: a whole number from 1 to 168.
  The default is 24.
- `options.onlyWhenAttention` is the quiet-day option. See below.

`POST /api/scheduled-reports/{id}/run-now` sends one at once, for a test.

A dialog on the Connections page to switch the report on is planned. Until it ships,
use the API above.

## What needs attention

A row needs attention when any of these is true:

- A connection has status `error`, `unavailable` or `limited`.
- A connection answers `simulated` where a real system is expected (it is not marked
  `reportedOnly`).
- A synchronisation had at least one failed run in the lookback window.
- A synchronisation ran in the window before but not in this one, so it stopped.
- A run has been `running` for more than 6 hours, so it is stuck.

The subject says how many rows need attention, for example
"Connection report 2026-11-13: 2 need attention". When none does, it says
"Connection report 2026-11-13: all clear".

## What the mail holds

- **Needs attention:** one line per row: the app, the connection or source, the status or
  failure, the message, since when, and a link to the settings or the synchronisation.
- **Runs per source system:** runs, succeeded, failed, found, created, updated and invalid,
  and when the last one finished.
- **All connections:** grouped by app, with title and status.
- **Attachment:** `connection-report-<date>.csv` with all three parts and a `section`
  column. Cells that start with `=`, `+`, `-` or `@` are prefixed with a quote, so a
  spreadsheet does not run them as formulas.

The reads are bounded: at most 1,000 connections and 5,000 runs, newest first. When a cap
is reached, the mail says so. The mail carries statuses and counts. It never carries a
source's credentials.

## Recipients

`recipients` takes e-mail addresses and the token `@admins`. The token stands for the
members of Nextcloud's `admin` group who have an e-mail address.

- An administrator without an address is skipped and named in the run record.
- A report goes to at most 20 recipients. Beyond that, the first 20 by user id get the
  mail and the run record says how many were left out.

## Quiet days

With `onlyWhenAttention` set to `true`, a run where nothing needs attention sends no mail.
The run is still recorded, as a success with "Nothing needed attention; no mail sent", so
you can see that the report ran.
