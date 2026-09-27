# schema-migration

## ADDED Requirements

### Requirement: The breaking-change gate shows who will be affected

The 409 that refuses an unacknowledged breaking schema change SHALL report the
number of accounts that called the schema's object routes in the last 90 days,
the ten busiest of them with their call counts and last call, and the number of
followers. When the caller record is switched off, the response SHALL say so
instead of reporting zero.

#### Scenario: the administrator sees two suppliers before acknowledging

- **GIVEN** a schema "Melding" whose objects two supplier accounts called last month
- **WHEN** a functional administrator sends a `PUT /api/schemas/{id}` that removes a required property, without `acknowledgeBreaking`
- **THEN** the response is 409 and `affectedCallers.count` is 2, with both accounts in `affectedCallers.top`
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/schema-change-notice.spec.ts}

### Requirement: An acknowledged breaking change tells the people who build on the schema

After a breaking schema change is acknowledged and applied, a background job
SHALL notify, once each, every account that called the schema's object routes
in the last 90 days and every follower of the schema, who can still read the
schema, up to 500 recipients. The notification SHALL name the schema, the new
version and the changelog, and SHALL carry the administrator's `changeNotice`
when one was given. The changelog entry SHALL record how many were told.

#### Scenario: a supplier is told

- **GIVEN** the same schema and change, now sent with `acknowledgeBreaking: true` and `changeNotice: "Field toelichting is removed, use omschrijving"`
- **WHEN** the notice job has run
- **THEN** each supplier account has a Nextcloud notification naming "Melding", the new major version and the note, linking to the schema changelog
- **AND** `GET /api/schemas/{id}/changelog` shows the entry with `noticeSentTo` 2
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/schema-change-notice.spec.ts}

### Requirement: A reader may follow a schema's breaking changes

A user who may read a schema SHALL be able to follow and unfollow its breaking
changes at `/api/schemas/{id}/change-followers`. A user who may not read the
schema SHALL receive 404.

#### Scenario: a data user follows a dataset

- **GIVEN** a signed-in data user who can read the "Melding" schema
- **WHEN** they call `POST /api/schemas/{id}/change-followers`
- **THEN** the response is 201, and the next acknowledged breaking change to "Melding" notifies them
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/schema-change-notice.spec.ts}

### Requirement: Clients see a breaking change on the schema's responses

For 30 days after a breaking change, responses of the schema's object read and
list routes SHALL carry a header naming the new version and the moment of the
change, and a `Link` to the schema changelog.

#### Scenario: an unattended client sees the header

- **GIVEN** a breaking change to "Melding" applied yesterday
- **WHEN** any client calls `GET /api/objects/{register}/melding`
- **THEN** the response carries `OpenRegister-Schema-Changed` with the new version and a `Link` to `/api/schemas/{id}/changelog`
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/schema-change-notice.spec.ts}
