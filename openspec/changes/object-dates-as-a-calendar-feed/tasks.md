# Tasks: object-dates-as-a-calendar-feed

## 1. The feed

- [ ] 1.1 A stable `text/calendar` URL per calendar-enabled schema and per saved view, answering the objects the named principal may list (D-2).
- [ ] 1.2 A revocable feed token per principal; a revoked or expired token answers 404 and never a partial calendar (D-2).
- [ ] 1.3 The feed is generated on read from the objects; an object that is deleted or archived leaves it (D-1).

## 2. Date kinds

- [ ] 2.1 A date property MAY declare kind `deadline`, `appointment` or `period`, validated at schema save; an unknown kind is refused naming the property.
- [ ] 2.2 A deadline publishes as an all-day VEVENT with the schema's declared alarm offset (D-4).
- [ ] 2.3 An appointment publishes with time and duration; a period publishes with start and end.
- [ ] 2.4 A deadline's date is read from the term engine, so the working calendar decides the day (D-5).

## 3. Attendees

- [ ] 3.1 An appointment created from an object collects attendee responses onto the object as they arrive (D-6).
- [ ] 3.2 The responses are readable with the object and carry the responder and the time of the answer.

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/object-calendar-feed.spec.ts`: declare a deadline, subscribe to the feed, move the deadline, refresh and see the new date.
- [ ] 4.2 Unit tests: per-principal resolution including a deny, the revoked token, the three date kinds, the working-day date and the alarm offset.
- [ ] 4.3 A regression test that a schema declaring no date kinds publishes no feed and behaves as before.
- [ ] 4.4 `openspec validate object-dates-as-a-calendar-feed --strict`.

## 5. Hand over

- [ ] 5.1 Hand the feed to the dossiq lane for `every-term-on-the-engine-calendar`, with candidate ids C-deadlines-22 and C-communication-52.
