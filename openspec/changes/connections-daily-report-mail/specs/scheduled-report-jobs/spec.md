# scheduled-report-jobs

## ADDED Requirements

### Requirement: A scheduled report can be a daily connection health report

A scheduled report SHALL carry a `kind`, `export` by default, which keeps today's behaviour. Kind `connection-health` SHALL need no register or schema, SHALL deliver by e-mail, and SHALL be created or changed only by administrators. Its run SHALL read, as its owner, the `app_connection` rows and the `synchronization_run` rows started within the lookback (24 hours by default) from register `integriq`, with the synchronizations and sources those runs name, each read bounded. When register `integriq` does not exist, the run SHALL be recorded as failed with that reason and no mail SHALL be sent.

#### Scenario: an administrator switches the daily report on

- **GIVEN** a functional administrator on the Connections page at `/settings/connections` with integriq installed
- **WHEN** they choose "Daily report by e-mail", pick "All administrators" and 07:00, and save
- **THEN** a scheduled report of kind `connection-health` exists with schedule `daily` and recipients `@admins`
- @e2e exclude {specified only; task 5.2 adds tests/e2e/ci/connection-report.spec.ts}

#### Scenario: a caseworker cannot create the kind

- **GIVEN** a signed-in caseworker who is not an administrator
- **WHEN** they call `POST /api/scheduled-reports` with `kind` `connection-health`
- **THEN** the response is 403 and no report is created
- @e2e exclude {specified only; task 1.1 covers it with a Newman request}

#### Scenario: no integriq, no false all clear

- **GIVEN** a connection report schedule and an instance where integriq was removed
- **WHEN** the report runs
- **THEN** the run is recorded as failed with "integriq is not installed, so there are no connection rows to report on"
- **AND** no mail is sent
- @e2e exclude {specified only; task 2.1 covers it in tests/Unit/Service/Connection/ConnectionHealthReportBuilderTest.php}

### Requirement: The report says what needs attention and what each source delivered

The connection report mail SHALL list first what needs attention: a connection with status `error`, `unavailable` or `limited`, or `simulated` where the connection is not reported-only; a synchronization with a failed run in the lookback; a synchronization that ran in the previous window and not in this one; and a run still `running` after 6 hours. It SHALL then list per source system the runs, succeeded, failed, found, created, updated and invalid counts and the last finish, and then every connection by app with its status. The subject SHALL state the number needing attention. A CSV with the same rows SHALL be attached, formula-safe. A read cap that was hit SHALL be stated in the mail. A source's credentials SHALL NOT appear in the mail.

#### Scenario: administrators read a morning with one failing pull

- **GIVEN** yesterday synchronization "Publicaties uit Zaaksysteem" ran 24 times, 2 of them failed with "401 Unauthorized", and connection `llm` of Open Register reports `unavailable`
- **WHEN** the daily connection report runs at 07:00
- **THEN** the administrators receive "Connection report {date}: 2 need attention"
- **AND** the first part lists the failing synchronization with its message and the `llm` connection with its status message
- **AND** the runs table shows 24 runs, 22 succeeded and 2 failed for that source, with the attached CSV holding the same rows
- @e2e exclude {specified only; task 2.2 covers the mail content in tests/Unit/Service/ScheduledReportConnectionHealthRunTest.php}

### Requirement: A quiet day can stay quiet

With the option `onlyWhenAttention`, a connection report run with nothing needing attention SHALL send no mail and SHALL be recorded as a success with "Nothing needed attention; no mail sent".

#### Scenario: nothing failed, nothing sent

- **GIVEN** a connection report with "Only when something needs attention" on, and a day where every connection is configured and every run succeeded
- **WHEN** the report runs
- **THEN** no mail is sent and the run record says nothing needed attention
- @e2e exclude {specified only; task 2.2 covers it in tests/Unit/Service/ScheduledReportConnectionHealthRunTest.php}

### Requirement: A report can go to all administrators

A scheduled report's recipients SHALL accept the token `@admins`, which SHALL expand at run time to the e-mail addresses of the members of Nextcloud's `admin` group. Members without an address SHALL be skipped and named in the run record. The expanded list SHALL respect the cap of 20 recipients, and an overflow SHALL be named in the run record.

#### Scenario: a new administrator is included without editing the report

- **GIVEN** a connection report addressed to `@admins`, and an administrator added to the `admin` group yesterday
- **WHEN** the report runs today
- **THEN** that administrator receives the mail
- @e2e exclude {specified only; task 3.1 covers it in tests/Unit/Service/ScheduledReportRecipientsTest.php}
