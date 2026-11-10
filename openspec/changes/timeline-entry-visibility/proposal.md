---
kind: code
depends_on: [activity-leaf]
---

# Proposal: timeline-entry-visibility

## Summary

Every entry on an object's timeline says which side of the counter it
belongs on. A note and a merged-feed row carry `visibility: internal | public`,
defaulting to internal. The activity leaf filters on it, so a portal reader
gets the public entries and nothing else. One flag, and it is what makes a
single timeline safe to show a citizen.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 6.15 | Internal or public visibility per timeline entry | no | S |

Third of the register's five to build first.

## Why

The register's note: "One column, and it is what makes a single timeline
safe to show a citizen. Row 6.4 is already `no` for the timeline itself, so
the flag has never been considered." The best competitor, verbatim from the
`best` column: "Zammad 7 ticket_articles.internal; osTicket thread type N;
seven of eight systems (`_round4/compare/promoted-rows-batch3.md`)".

The register's `why`: "internal versus public is a flag on the feed entry
the activity and notes leaves render; the portal reads public only".
`activity-leaf` merges five sources into one feed; `object-interactions`
stores notes as Nextcloud comments. Both need the flag; the portal (ADR-046
subject-scoped reader) needs the filter.

## What changes

- A note accepts `visibility` on create and update, `internal` by default,
  stored on the comment and returned on every read. Only a user with
  `update` on the object may set or change it.
- The merged feed row carries `visibility`. Audit rows and NC Activity rows
  are always `internal`. A file event and a mail row inherit the visibility
  their source declares (a file share to the citizen is public; a linked
  mail is internal unless linked as public).
- `GET .../activity?visibility=public` returns public entries only; a
  caller without `update` on the object is served the public view whatever
  they ask for.
- The notes leaf shows the flag as a chip and offers the toggle to users who
  may set it; the feed offers a filter chip.

## Consumers

- dossiq: default every entry internal; Berichtenbox and portal messages
  public; the portal timeline filters on it. Specified in dossiq by the
  dossiq lane (register row 6.15).
- portaliq: its case timeline reads `visibility=public` through the
  subject-scoped reader (ADR-046).
- zaakafhandelapp, pipelinq (customer-visible replies), decidiq.

## ADRs

- ADR-046 (portal contribution contract): data reaches a visitor only
  through subject-scoped readers; the flag is what those readers filter on.
- ADR-108 (public surface placement): the public timeline is portaliq's.
- ADR-022, ADR-031.
- openregister ADR-006: like "published", visibility is a scope, not a
  business field on the object.

## Impact

- Extends: `object-interactions` requirement "Notes on Objects via
  ICommentsManager" and `integration-activity` requirement "Blended Feed"
  (the merged feed of `activity-leaf`).
- Affected code: `lib/Service/Interaction/NoteService.php` (comment
  metadata), `ActivityProvider` (merge and filter), the notes and activity
  leaf Vue surfaces.
- Backwards compatible: every existing entry reads as internal.
- Size: S.
