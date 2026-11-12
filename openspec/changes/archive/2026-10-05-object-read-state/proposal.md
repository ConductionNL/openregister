---
kind: code
depends_on: [favourites-and-recent]
---

# Proposal: object-read-state

## Summary

"What moved overnight" is the cheapest question a caseworker asks and the
one nothing in the fleet answers. An object carries no per-user read
state, so a list cannot show what is new to you, a tab cannot badge an
unread document, and a notification stays in the bell after you have done
the work it asked for. This change gives an object a read state per user,
clears it from the work rather than from the bell, and lets a notification
be snoozed, archived and filtered by what it is about.

## Candidates and cluster

Cluster 62 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Per-user unread state and
what clears it". Owner openregister, size M, six candidates:
C-case-core-26, C-search-1, C-communication-6, C-communication-15,
C-communication-18, C-communication-61. Relevance `should`, no `must`.
Passers: seven, all driven. dossiq rates `no` on all six.

## Why

The proving system is otobo, cited by the search lane at `search.tsv:11`:
"Ticket menu, Mark as unseen (AgentTicketMarkSeenUnseen.pm)". A case
carries a per-user unread state, shown in the list and settable back to
unread. frappe-helpdesk passes the same candidate, driven.

- **xxllnc-zaken**, `case-core.tsv:46`: "Case page, side menu
  (case-detail-anatomy.md)". An unread badge on the case's own tabs when
  files await acceptance or messages are unread. The clause names the
  failure it prevents: a document sitting unseen on a case for a week.
- **dimpact-zac**, `communication.tsv:51`: "Signaleringen
  (signalering-notifications/spec.md)". A notification clears itself when
  the user opens what it was about. The candidate note is worth quoting:
  nobody else in the corpus clears an alert by the work being done.
- **plane**, `communication.tsv:43`: "Notifications,
  db/models/notification.py:13 with read_at, snoozed_till at :32 and
  archived_at at :33". A bell that only empties by being read is a bell
  people stop looking at.
- **forgejo** and **gitea**, `communication.tsv:54`:
  "/notifications?subject-type= and /notifications/threads/{id} (api.go)".
  A bell with four hundred entries needs an axis.
- **xxllnc-zaken**, `communication.tsv:62`: "Case page, Communicatie
  (communication/spec.md)". A message is marked read or unread by hand.

**What exists here and does not close it.** `notificatie-engine` tracks
read and unread per notification per user and answers an unread count. That
is the bell. It says nothing about whether you have seen the object, it is
not cleared by opening the subject, and it offers neither snooze nor
archive nor a filter by subject type. `favourites-and-recent` records a
per-user view history, which is the nearest thing in the repository and is
a different fact: it says you looked, not that nothing has changed since.

## What changes

- **An object carries a read state per user.** Last seen at, and a
  derived unread flag against the object's last substantive change. A user
  marks an object read or back to unread by hand.
- **Unread is a query axis.** The object list and the search filter on it
  and return it, so a saved view can be "unread only" and a list can render
  the badge without a second call per row.
- **Sub-resources carry their own unread counts.** Files, messages and
  notes on an object report how many are unread for this user, so a tab
  badges without loading the tab.
- **What clears it is the work.** Opening the object clears the object,
  opening the sub-resource clears the sub-resource, and any notification
  whose subject is that object or sub-resource is cleared with it. The
  bell empties because the work was done.
- **A notification is snoozed or archived.** `snoozedUntil` returns it to
  the bell on a date; `archivedAt` takes it out without pretending it was
  read.
- **The bell has an axis.** Notifications filter by subject type and by
  object, and a thread is marked read as a whole.
- **A substantive change is declared.** A schema says which properties and
  which sub-resources make an object unread again, so a technical touch or
  a computed field recalculation does not mark four hundred objects unread
  overnight.

## Consumers

- **dossiq**: the unread badge on the case tabs and the unread column in
  the werkvoorraad, which the build plan names as the consuming half
  together with the fleet's list components.
- **nextcloud-vue**: one unread affordance for every list and every detail
  page in the fleet, over one envelope.
- **pipelinq, humaniq, keepiq, decidiq**: the same, with nothing to build.

## ADRs

- ADR-022: read state is a property of the object, so it lives in the
  layer that owns objects.
- ADR-031: what counts as a substantive change is declared on the schema,
  not decided in a leaf app's controller.
- ADR-005: the read state is resolved per principal and is never shared
  between users.

## Impact

- New capability `object-read-state`. Extends `notificatie-engine` with
  snooze, archive, the subject-type filter and clearing by the subject
  being opened.
- Affected code: a read-state store keyed by user and object, the object
  query filter and its index, the render path for the flag and the
  sub-resource counts, the notification store and its controller.
- Backwards compatible: an instance where nothing reads the flag behaves
  as today, and the notification envelope gains fields rather than
  changing.
- Size: M.

## Out of scope

- One personal queue fed by every mechanism, which the build plan puts on
  dossiq in cluster 63.
- Notification preferences per user, group and template, which is cluster
  10 and its own change.
- A read receipt shown to the sender. Who has read what about whom is a
  different question with a works-council answer, not a list affordance.
