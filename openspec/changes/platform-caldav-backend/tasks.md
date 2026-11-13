# Tasks: platform-caldav-backend

- [x] 1.1 Calendars per calendar-enabled schema and per saved view, exposed through the platform calendar provider (D-3).
- [x] 1.2 Built from the same event generator as the subscribable feed (D-1).
- [x] 1.3 Declared date kinds, alarm offsets and the term engine's working-day date carried through.
- [x] 1.4 Read-only: a client write is refused, not accepted and overwritten (D-2).
- [x] 1.5 Per-principal resolution through the object access path, deny included.
- [x] 1.6 A schema declaring no date kinds keeps today's virtual calendar, with a regression test (D-4).
- [ ] 2.1 `tests/e2e/ci/platform-caldav.spec.ts`: a declared deadline visible in the Calendar app, matching the feed.
- [x] 2.2 Unit tests: the shared generator's agreement, the refused client write, the per-principal split, the saved-view calendar.
- [ ] 2.3 `openspec validate platform-caldav-backend --strict`.
- [ ] 3.1 Hand over to the dossiq lane: the dates are already declared for the feed and nothing more is needed.
- [ ] 3.2 Tell the D11 lane that writing back waits on `calendar-change-recomputes-timers`.
