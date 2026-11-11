---
kind: code
---

# Proposal: connections-daily-report-mail

## Summary

A functional administrator switches on a daily connection report from the Connections page. Every morning the administrators get one mail: which connections need attention, and per source system which scheduled pulls ran in the last day, how many succeeded or failed, and what they delivered. The same tables come as a CSV attachment. An administrator can choose to get the mail only on days when something needs attention. The report reads the connection rows and run records that integriq already keeps, and it goes out through Open Register's scheduled report mail.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| opencatalogi | int-connector-report | Have that daily connection report emailed to the administrators automatically. | no |

**int-connector-report** (row in opencatalogi's matrix, owned here because built.owner is ConductionNL/openregister)

- Demand: tender, https://www.tenderned.nl/aankondigingen/overzicht/407973 (the row's origin; the matrix names wish DMK-05-KW-02 of the Drechtsteden Woo Publicatietool).
- Competitor yes cells:
  - ckan (CKAN), no evidence URL, source path cited: "source read at ckanext-harvest v1.6.2: with ckan.harvest.status_mail.all = True every finished harvest job sends a summary mail, and with status_mail.errored only failed ones (ckanext/harvest/logic/action/update.py:676-684, send_summary_email :778-781, template emails/summary_email.txt :760); recipients are all sysadmins plus the admins of the source's organisation (README.rst:191-202). It is one mail per job per source, so a daily source gives a daily report."
- The matrix notes the row depends on `int-connector-monitor` (the per-source run overview), owned by integriq. The run records that overview is built on already exist, see "Why".

## Why

The data for the report exists in two places, and nothing mails it.

- Connection status lives in integriq's `app_connection` objects in register `integriq`, synced from each app's `lib/Settings/connections.json` (hydra change `connection-registry`, design D3 and D4). Open Register only reports into them through `ConnectionReporter::report()` (`lib/Service/Connection/ConnectionReporter.php:147`) and lists them on its Connections page, `/settings/connections` (`src/manifest.json`, page `connections`, `register: integriq`, `schema: app_connection`).
- Scheduled pulls are recorded as `synchronization_run` objects in the same register, with `status` (`running`, `success`, `failed`), `startedAt`, `finishedAt`, `found`, `created`, `updated`, `deleted`, `invalid` and `message` (integriq development 23c672699d, `lib/Settings/register.d/sync-run-progress.json`), each pointing at a `synchronization` that names its source.
- Open Register can already mail a scheduled report: `ScheduledReportService::runOne()` (`lib/Service/ScheduledReportService.php:601-680`) runs a report as its owner and `deliverToEmail()` (`:1065-1134`) sends it through `IMailer` with an attachment. But a report is always an object export of one register and schema (`runExport()`, `:739-767`) or an export profile, with a body that names a register and a row count (`buildEmailBodyLines()`, `:1176-1201`). It cannot say "these three connections are failing".
- No page in `src/` creates a scheduled report: a search for `scheduled-reports` in `src/` finds nothing, so the only way to set one up today is the API.
- integriq has no report mail either; its sync-failed notification rule is disabled (row evidence, `lib/Settings/integriq_register.json:2274-2280` in integriq).

## What changes

- A scheduled report gains a `kind`. The existing behaviour is kind `export`. A new kind `connection-health` needs no register or schema and is administrator-only.
- A connection-health run reads `app_connection` rows and the last 24 hours of `synchronization_run` rows from register `integriq`, bounded, as its owner.
- The mail has three parts: what needs attention, runs per source system, and every connection by app with its status. A CSV with the same rows is attached.
- A recipient token `@admins` sends to every member of Nextcloud's `admin` group who has an e-mail address, within the existing cap of 20 recipients.
- An option `onlyWhenAttention` skips the mail on a quiet day and still records the run.
- The Connections page gets a "Daily report by e-mail" header action that opens a dialog to switch the report on, pick recipients and the hour, and send a test now.
- Without integriq installed the kind is not offered, and an existing schedule records a failed run that says so.

## Consumers

- opencatalogi: the row is in its matrix; its administrators get the report for the harvest and sync sources behind their catalogue.
- integriq: its synchronizations and connections are what the report is about; integriq needs no code change.
- Every app that adopted the connection registry (dossiq, decidiq, pipelinq, shillinq and Open Register itself) has its connection rows in the report.

## ADRs

- hydra ADR-022 (apps consume OR abstractions): the report reads integriq's rows as Open Register objects through `ObjectService`; there is no PHP dependency on integriq.
- hydra ADR-041 (cross-app commands via events) and the `connection-registry` change: the register slug `integriq` and the schema slugs are integriq's published data contract, the same contract Open Register's Connections page already reads.
- hydra ADR-058 (bounded object queries): the run read is capped and filtered on `startedAt`.
- hydra ADR-004 (frontend): the dialog lives in `src/dialogs/`.
- openregister ADR-002 (organisation tenancy) and hydra ADR-005: the kind is administrator-only because `app_connection` is an administrator-only schema, and the run executes as its owner as every scheduled report does.

## Impact

- Extends the capability `scheduled-report-jobs`.
- Affected code: `lib/Db/ScheduledReport.php` and a migration (`kind`, `options`), `lib/Service/ScheduledReportService.php` (`validate()`, `runExport()`, `resolveRecipients()`, `buildEmailBodyLines()`), a new `lib/Service/Connection/ConnectionHealthReportBuilder.php`, `lib/Controller/ScheduledReportsController.php` (kind-specific admin check), `src/manifest.json` (header action), `src/customComponents.js`, a new `src/dialogs/connections/ConnectionReportDialog.vue`, `src/dialogs/Dialogs.vue`.
- Backwards compatible. Existing reports read as kind `export` and behave as today.
- Size: M.

## Out of scope

- The per-source run overview page itself (`int-connector-monitor`), which is integriq's.
- One mail per finished run, as CKAN does. A daily digest is what the row asks; per-run alerts are the notification engine's.
- Restarting a failed pull from the mail. The mail links to integriq's synchronization page where "Run now" already exists.
