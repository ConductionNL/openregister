---
kind: code
depends_on: [object-dates-as-a-calendar-feed]
---

# Proposal: platform-caldav-backend

## Summary

`OCA\DAV\CalDAV\Integration\ICalendarProvider` is what makes a calendar
appear in the Nextcloud Calendar app and in every CalDAV client a
caseworker already has. Wave 1's `object-dates-as-a-calendar-feed`
publishes a subscribable feed, which is D11's option 3 delivered first.
This change is option 1 arriving behind it: the same dates, as a calendar
the platform owns, in the app people actually open.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`, names the CalDAV backend beside the ten
registrations, and the consequence: a zaak "is in nobody's calendar".
Candidate C-deadlines-22 is the strongest single capability in the whole
sweep, thirteen passers and twelve driven, and C-deadlines-24 asks
specifically whether "a caseworker's calendar client sees and edits their
cases over CalDAV", with the lane noting eleven systems publishing a feed
or a server, "from GLPI's read-write CalDAV to OTOBO's read-only
text/calendar".

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface. **D11, option 1 delivering option 3
first**, is why this change extends wave 1's feed rather than replacing
it.

## What openregister implements generically

- A `ICalendarProvider` exposing, per principal, a calendar per
  calendar-enabled schema and per saved view, built from the same
  generator the feed uses, so the two surfaces cannot disagree.
- **The same date kinds, the same access and the same working calendar.**
  `object-dates-as-a-calendar-feed` owns the declaration of `deadline`,
  `appointment` and `period`, the per-principal resolution and the
  term-engine date. This change renders them through the platform's
  calendar plane rather than through a feed URL.
- **Read-only first.** The calendar is generated from the objects, so an
  event moved in a client would be overwritten at the next read. Writing
  back is a separate question and it waits for
  `calendar-change-recomputes-timers`, exactly as D11 argued for the feed.
- **The calendar appears where calendars appear**: the Calendar app,
  a CalDAV client, and the scheduling surfaces the platform offers.

## What a leaf app declares

dossiq declares which dates count per case type, which it already does for
the feed. It registers no provider and mints no URL.

## What exists and what is missing

`calendar-provider` registers an `ICalendarProvider` and turns
calendar-enabled schemas into virtual read-only calendars, with RBAC
enforced on the queries. `integration-calendar` puts a calendar on a
sidebar tab and a widget. `object-dates-as-a-calendar-feed` adds the date
kinds, the subscribable feed, the per-principal token and the working-day
date. What is missing is the join: the platform calendar does not yet
carry the declared date kinds, the alarm offsets or the saved-view
calendars, so the feed and the in-platform calendar show different things.

## Impact

- Extends: `calendar-provider`, and wave 1's
  `object-dates-as-a-calendar-feed`, which it depends on.
- Affected code: `RegisterCalendarProvider` and the virtual calendar
  search, the shared event generator, the saved-view calendar resolution.
- Backwards compatible: a schema declaring no date kinds keeps today's
  virtual calendar behaviour.
- Size: M.

## Out of scope

- Writing an event back to an object. D11 says publish first, and
  `calendar-change-recomputes-timers` has to land before a write is safe.
- Rostering, availability and resource booking, which D19 puts in humaniq.
- The working calendar itself, which `working-calendar-admin` owns.
