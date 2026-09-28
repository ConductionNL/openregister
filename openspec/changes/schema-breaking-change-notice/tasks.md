# Tasks: schema-breaking-change-notice

## 1. Who is affected

- [ ] 1.1 `ApiCallRecordMapper::findCallersOfRoutes()` matching slug and id prefixes, summed per principal, capped. Verify: mapper test with two callers on slug and id routes and one on another schema.
- [ ] 1.2 Follower table, mapper and `POST`/`DELETE /api/schemas/{id}/change-followers` with the read check. Verify: controller test for follow, unfollow, 404 without read, idempotent follow.

## 2. Gate and notice

- [ ] 2.1 `affectedCallers` and `followers` on the 409, and a clear flag when the caller record is off. Verify: `SchemasControllerTest` asserts the keys on a breaking update without acknowledgement.
- [ ] 2.2 `SchemaChangeNoticeJob` with deduplication, read re-check, the 500 cap, `changeNotice`, and the count on the changelog entry; `schema_breaking_change` in `Notifier` in en and nl. Verify: `tests/Unit/BackgroundJob/SchemaChangeNoticeJobTest.php` and a `Notifier` test for the new subject.
- [ ] 2.3 The response header and `Link` for 30 days on the schema's object routes, memoised per request. Verify: listener unit test on a list of many objects doing one lookup.

## 3. Tests and docs

- [ ] 3.1 Add `tests/e2e/ci/schema-change-notice.spec.ts`: a second user calls a schema's objects, a third follows it, an administrator attempts and then acknowledges a breaking change; both users see the notification and an object read carries the header.
- [ ] 3.2 Document following a schema and the notice in `docs/features/`, with a screenshot of the notification.

Acceptance:

- An unacknowledged breaking change answers 409 with the number of callers from the last 90 days.
- Nobody who cannot read the schema is told.
