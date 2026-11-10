---
kind: code
depends_on: [working-calendar-admin]
---

# Proposal: object-dates-as-a-calendar-feed

## Summary

A statutory term that nobody sees is a term that gets missed. Thirteen
systems put the work and its dates in the caseworker's own agenda, twelve
of them driven, which makes it the strongest single capability in the
round 4 sweep. OpenRegister registers a calendar provider inside Nextcloud
already. What it does not do is publish a subscribable feed that a
caseworker adds to the agenda they actually read, and that is what this
change adds first.

## Candidates and cluster

Cluster 9 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The case and its term in
the caseworker's own calendar". Owner openregister, size M, two
candidates: C-deadlines-22 (`must`, a matrix hole) and C-communication-52
(`should`). Passers: 14, thirteen driven and one documented. dossiq rates
`partial` on both.

## The decisions this rests on

**D11, option 1 delivering option 3 first.** Ruben took the
recommendation: a CalDAV provider in openregister, so any object with a
date publishes a feed and a leaf app declares which dates count, and the
read-only feed ships before anything writes an event. A feed that
recomputes is correct by construction; a written event needs the
recomputation that `calendar-change-recomputes-timers` is still
specifying, and a moved term leaves a stale event behind.

**D5, all five revivals return.** The calendar cluster stays in wave 1
under that decision rather than being re-argued.

## Why

The proving system is zammad, cited by the cross-area lane at
`cross-area.tsv:1`: "Calendar subscriptions
(lib/calendar_subscriptions/tickets.rb:4, ALLOWED_METHODS = all new_open
pending escalation, :64-76 the escalation feed, area
Defaults::CalendarSubscriptions)". A feed per saved selection, with the
escalation dates as their own feed, read-only. Eleven of the thirteen
passers are read-only in the same way.

The full driven set for C-deadlines-22 is gitlab, glpi, kanboard,
nextcloud-deck, openproject, otobo, redmine, request-tracker, tuleap,
vikunja, zammad and znuny, with easy-redmine documented. The candidate
clause is one sentence: a beslistermijn belongs in the agenda the
caseworker actually reads.

For C-communication-52 the driven passer is odoo, cited at
`communication.tsv:57`: "Calendar, calendar_event.py +
calendar_attendee.py". People are invited from a record and each answer is
collected.

**What exists here and does not close it.** `calendar-provider` registers
an `ICalendarProvider` and turns calendar-enabled schemas into virtual
read-only calendars inside Nextcloud, with RBAC enforced on the queries.
`integration-calendar` puts a calendar on a sidebar tab and a widget.
Both surface dates inside Nextcloud's own apps. Neither publishes a URL a
caseworker subscribes to from a phone or from Outlook, and neither lets a
schema say that a date property is a deadline rather than an appointment.

The dossiq reading is flat across seven lane notes: "HearingCalendarService.php
builds hearing invites; no term is published as a calendar", and "zero
hits for an iCal or CalDAV surface". Hearings are pushed one way into a
Nextcloud calendar at `HearingCalendarService.php:86` and nothing is read
back, which is also why the attendee answers of C-communication-52 are
absent.

## What changes

- **A subscribable feed per calendar-enabled schema and per saved view.**
  A stable URL answers `text/calendar` for the objects the caller may
  read, resolved per principal, so the feed shows one person their own
  work. A view's feed carries the view's filters.
- **A date property declares what kind of date it is.** `deadline`,
  `appointment` or `period`. A deadline publishes as an all-day VEVENT on
  its date with an alarm offset the schema declares; an appointment
  publishes with its time and duration; a period publishes with a start
  and an end.
- **The feed recomputes on read.** Nothing is written into a calendar, so
  a term that moves is correct on the next refresh and no stale event
  survives. A deleted or archived object leaves the feed.
- **Access is the object's access.** The feed is generated per principal
  under the same rules as the object list, including a deny. A feed URL
  that leaks is a read of nothing the holder could not already read, and
  the token is revocable.
- **The working calendar is honoured.** A deadline that falls on a
  non-working day publishes on the day the term engine actually uses,
  which is why this change depends on `working-calendar-admin`.
- **Attendee answers are read back.** For an appointment created from an
  object, the invitee responses are stored against the object, so
  "who is coming to the hoorzitting" is a property of the record and not
  of one person's calendar.

## Consumers

- **dossiq**: `every-term-on-the-engine-calendar`, which the build plan
  names as the consuming half. dossiq declares which dates count per case
  type, and `HearingCalendarService` stops being the only path to an
  agenda.
- **humaniq, pipelinq, decidiq, keepiq**: a date on any object reaches an
  agenda with no work per app, which is the ownership rule doing its job.
- **portaliq**: a published appointment a citizen can add to their own
  calendar, over the same generator with a narrower principal.

## ADRs

- ADR-022: the calendar is a platform capability, specified once and
  consumed by every leaf app.
- ADR-005: the feed resolves access per principal and fails closed. An
  unresolvable principal returns an empty calendar, never the register.
- ADR-031: which dates publish is declared on the schema, not coded per
  app.

## Impact

- Extends: `calendar-provider` (the feed, the date kinds and the per
  principal resolution) and `integration-calendar` (the same dates on the
  sidebar tab, unchanged in shape).
- Affected code: `RegisterCalendarProvider` and the virtual calendar's
  search, a feed controller and its token, the schema calendar
  configuration and its validator.
- Backwards compatible: a schema that declares no date kinds behaves as
  today and publishes no feed.
- Size: M.

## Out of scope

- Writing an event into a calendar. D11 says publish first, and the write
  needs `calendar-change-recomputes-timers` to have landed.
- Rostering, availability and resource booking. D19 puts those in humaniq.
- The working calendar itself, which is `working-calendar-admin`.
