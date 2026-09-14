# Tasks: working-calendar-admin

## 1. Validation and authorization

- [x] 1.1 Schema hook on `working-calendar` create and update calling `WorkingCalendar::fromArray()`, 422 on throw.
- [x] 1.2 Delete hook refusing a calendar named by an armed or suspended timer (409, count, ten uuids).
- [x] 1.3 Authorization block on the `flow-timers` register: read authenticated, write admin. Seed repair step re-run stays idempotent.

## 2. Admin surface

- [x] 2.1 `POST /api/flow-timers/calendars/preview` (admin) returning the non-working dates of a year for an unsaved definition.
- [x] 2.2 Working calendars section on the OpenRegister admin settings page: list, edit form for weekdays, hours, rules, exceptions, organisation, year preview.
- [x] 2.3 Notice on save that armed deadlines keep their dates until `calendar-change-recomputes-timers` lands.

## 3. Tests and docs

- [x] 3.1 Unit tests for the hooks; Newman case for the objects API round trip and the 422.
- [x] 3.2 `tests/e2e/ci/working-calendar-admin.spec.ts`: add an exception, preview, save, read back.
- [x] 3.3 Document the register and schema as the calendar API in `website/docs`.
