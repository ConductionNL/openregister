# Tasks: the-working-calendar-carries-its-zone

- [x] 1.1 `WorkingCalendar` carries `timezone`, validated against the IANA
  list, defaulting to `UTC` and never to the server's setting.
- [x] 1.2 `timezone` is declared on the `working-calendar` schema and set to
  `Europe/Amsterdam` on the seeded `nl-national` calendar.
- [x] 1.3 The admin preview echoes the validated zone.
- [x] 2.1 Unit tests, including the default measured from a process pointed
  at another zone. `openspec validate the-working-calendar-carries-its-zone
  --strict`.
