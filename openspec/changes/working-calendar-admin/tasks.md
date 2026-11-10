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

## Discovery cluster 67

- [ ] C67.1 A calendar named on a schema and on an organisational unit, resolved record type, unit, instance, with fall-through (D-C67-1).
- [ ] C67.2 The resolved calendar named in the term diagnostic.
- [ ] C67.3 A provider seam for a person's working pattern, read and never stored here (D-C67-2).
- [ ] C67.4 The diagnostic says whether the person's pattern or the scope calendar applied.
- [ ] C67.5 Blackout periods per calendar, refusing a booking without stopping a term (D-C67-3).
- [ ] C67.6 A declared first week of the year, followed wherever a week number is reported.
- [ ] C67.7 Tests: two schemas on two calendars, an unchanged undeclared instance, the pattern provider and its absence, a refused booking with a running term.
- [ ] C67.8 Hand over to the humaniq lane for the working-pattern provider under D19, and to the dossiq lane for `terms-on-the-engine-calendar`, with candidate ids C-deadlines-3, -11, -14 and -23.
