# Tasks: calendar-leaf-inbound

- [ ] `CalendarLinkMapper::findByEventUid(eventUid, ?calendarUri)` — reverse lookup, scoped by calendar uri when given (REQ-CAL-INB-002).
- [ ] `CalendarLinkService::getObjectsForEvent()` — service surface over the reverse lookup.
- [ ] Idempotency in `CalendarLinkService::linkEvent` / `createAndLinkEvent` — return the existing row for a duplicate `(objectUuid, eventUid, calendarUri)` instead of inserting (REQ-CAL-INB-003).
- [ ] Migration — deduplicate existing `openregister_calendar_links` rows (keep oldest) and add the unique index on `(object_uuid, event_uid, calendar_uri)`.
- [ ] `lib/Listener/CalendarObjectChangedListener.php` — one listener class handling Created/Updated/Moved: parse the VObject from the event payload, match by `X-OPENREGISTER-*` or `findByEventUid`, upsert/refresh cached fields; swallow+log own failures, never abort the DAV operation (REQ-CAL-INB-001).
- [ ] `lib/Listener/CalendarObjectDeletedListener.php` — delete matching link rows on VEVENT deletion.
- [ ] Register both listeners in `lib/AppInfo/Application.php` (`registerEventListener`) for the four `OCA\DAV\Events\CalendarObject*Event` classes, guarded so absence of the DAV classes is a no-op.
- [ ] Register `BackfillCalendarLinksJob` (info.xml background-jobs / boot registration) and flip the `backfill_calendar_links` default read to enabled; keep `no` as the honoured kill switch; remove the stale "not registered / manual only" doc block wording (REQ-CAL-INB-004).
- [ ] `CalendarEventService::createEvent` — accept `reminderMinutesBefore`, emit `BEGIN:VALARM / ACTION:DISPLAY / TRIGGER:-PT{n}M / DESCRIPTION / END:VALARM`; expose `reminderMinutesBefore` in `veventToArray` when a display alarm is present (REQ-CAL-INB-005).
- [ ] PHPUnit — listener upsert/refresh/delete paths, reverse lookup, idempotent link (service + repeated HTTP link route), migration dedup, backfill re-run idempotency and kill switch, VALARM serialization and absence.
- [ ] `composer check:strict` clean on all touched files; hydra gates pass; `openspec validate --strict` on this change.
