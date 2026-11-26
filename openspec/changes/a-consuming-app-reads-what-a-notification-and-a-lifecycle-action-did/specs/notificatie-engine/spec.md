## ADDED Requirements

### Requirement: A consuming app reads what a notification and a lifecycle action did

OpenRegister SHALL keep, for 90 days, one record per notification it sends
(`kind: notification`) and one per typed lifecycle action it runs
(`kind: lifecycle`), each with the declared key, the time, the subject object
(register, schema, id), and `outcome` `sent`/`done` or `failed` with a short
reason; a notification record SHALL also carry the number of recipients. It
SHALL answer them through `GET /api/automation-outcomes` filtered by
`keyPrefix` and `since`, newest first, paginated, only for subject objects the
caller may read. A record SHALL hold no message body and no recipient address.

#### Scenario: a maker sees that the reminder went out

- GIVEN an automation compiled to the notification key `aut-7f3c-reminder`
- AND OpenRegister sent it on Tuesday for case `Z-2026-0042` to two people
- WHEN buildiq asks `GET /api/automation-outcomes?keyPrefix=aut-7f3c`
- THEN the answer holds one `notification` record for `Z-2026-0042` with `outcome: sent` and `recipients: 2`

#### Scenario: a failed lifecycle action says why

- GIVEN an automation whose typed lifecycle action `aut-9a1d-close` could not run because the case was locked
- WHEN buildiq asks for its outcomes
- THEN the answer holds one `lifecycle` record with `outcome: failed` and the reason

#### Scenario: another department's case stays out

- GIVEN outcome records for a case the caller may not read
- WHEN the caller asks for those keys
- THEN those records are not in the answer
