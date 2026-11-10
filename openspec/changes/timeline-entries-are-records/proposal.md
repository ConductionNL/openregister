---
kind: code
depends_on: [timeline-entry-visibility, note-edit-history, object-watchers]
---

# Proposal: timeline-entries-are-records

## Summary

A Woo request asks for every mention of a subject, and the mentions live
in the timeline. OpenRegister searches objects and not the entries on
them, keeps no raw inbound message, and lets an entry carry no fields of
its own. This change makes a timeline entry a record: searchable across
objects, carrying declared fields, pinnable, kept exactly as it arrived,
and reachable by a short code typed in any sentence.

## Candidates and cluster

Cluster 13 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The timeline, the note
and what can be searched in it". Owner openregister, size M, fifteen
candidates: C-communication-1, -3, -8, -14, -20, -27, -30, -36, -38, -41,
-42, -49, -59, -60 and C-search-39. Two are `must`. No matrix hole.
Passers: 14, eleven driven and three documented. dossiq rates `partial` on
two and `no` on thirteen, which makes it the fifth heaviest cluster by
that measure.

Ledger row the candidate notes name: 6.21.

**Why this is a change and not an extension.** The build plan names two
vehicles for this cluster, `timeline-entry-visibility` and
`note-edit-history`. Both are small and open, each answers one gap register
row, and fifteen candidates do not fit inside either without burying what
each already says. This change depends on both instead, and takes nothing
they carry.

## The decisions this rests on

**D6, relevance-led promotion.** C-search-39 is a `must` with one driven
passer and C-communication-1 is a `must` with a documented one. Both enter
on relevance.

**D21, documented candidates admitted and labelled.** C-communication-1
(visma-circle) has no driven passer and is in scope as documented.

**D1, the dossiq-only rows.** Row 6.21 is renumbered centrally.

## Why

The proving system is znuny, cited by the communication lane at
`communication.tsv:17`: "Mention view (AgentTicketMentionView.pm,
Modules/Mentions.pm, System/Mention.pm, AJAXRichTextAutocompletion.pm)".
Naming a colleague in a note notifies them and makes them a follower, with
a view of everything they were named in. dossiq's lane is exact about the
half that exists: "NotesController.php:11 turns @mention tokens into
Nextcloud notifications; no mention view and no subscription".

The rest:

- **Timeline entries searched across all cases** (C-search-39, `must`):
  request-tracker, "Search, Transactions (MenuBuilder.pm,
  TransactionSQL)". dossiq's lane: "dossiq search is over objects".
- **A callback request tracked until it is confirmed to have happened**
  (C-communication-1, `must`, documented): visma-circle,
  "/software/klantcontact". Nobody called back and nobody knows.
- **The message readable exactly as it arrived** (C-communication-60):
  otobo, "View raw message (AgentTicketPlain.pm)", and freescout. A
  disputed ontvangstdatum is settled by the headers, and
  "InboundEmailJob.php keeps no raw source".
- **The language a message arrived in, recorded and searchable**
  (C-communication-59): zammad, "Language detection on a message
  (app/models/ticket/article.rb:97-99, lib/language_detection_helper.rb,
  setting language_detection_article, indexed as detected_language_name)".
- **A short code in any text field becomes a link, the pattern
  administered** (C-communication-27): tuleap, "/admin/references,
  src/common/Reference, plugins/tracker/include/Tracker/Reference".
  ZAAK-2026-0412 written in a note should reach the zaak.
- **Custom fields on a timeline entry, not on the case**
  (C-communication-42): request-tracker, "Queue editor, Transaction Custom
  Fields tab". A contactmoment needs a channel and a direction.
- **An entry pinned to the top** (C-communication-36): freescout, "Manage,
  Modules, sticky-notes". A bezwaardossier's timeline is three hundred
  entries and four of them are the ones a colleague needs.
- **A note written once lands on the related case as well**
  (C-communication-14): znuny, "Note to a linked case
  (AgentTicketNoteToLinkedTicket.pm)".
- **Canned text kept and inserted** (C-communication-41): kanboard,
  "Configure this project, Predefined contents". A klantcontactcentrum
  answers the same twenty questions.

**What exists here and does not close it.** `object-interactions`
specifies notes as Nextcloud comments, OpenRegister as a comments entity
type, tasks over CalDAV, file attachments, tags, audit integration and a
unified interaction timeline API. `activity-leaf` merges five sources into
one feed with an export. `timeline-entry-visibility` adds internal or
public per entry. `note-edit-history` keeps what a note said before.
`unified-search-provider` searches objects, with a `searchable` flag per
schema, per-app labelling and a cursor. Between them the timeline renders
well and its entries are comments. What no artefact carries: the entries
are not in the search index, the raw inbound source is thrown away, an
entry cannot carry a field, nothing pins, and no reference pattern exists.

## What changes

- **Timeline entries are searched across objects.** An entry is indexed
  with its object, its kind, its author, its time, its visibility and its
  declared fields. The search honours the same access as the object,
  including the internal or public flag. A hit names the object and the
  entry.
- **An entry carries declared fields and a kind.** A kind declares its own
  properties, so a contactmoment carries a channel and a direction without
  a second schema. Validation is the same validation objects get.
- **A kind may carry a follow-up state.** Open, done, with who closed it
  and when. A callback request is then a question the list can answer.
- **An entry is pinned.** Pinned entries sort first and say who pinned
  them. Pinning is an act on the record, not a per-user preference.
- **The inbound message is kept exactly as it arrived.** The raw source and
  its headers are stored beside the rendered entry, readable by anyone who
  may read the entry, and the detected language is recorded and searchable.
- **A reference pattern is administered.** An administrator declares a
  pattern and the target it resolves to. A match in any text on an object
  renders as a link and records a reference on both sides.
- **A note can be written to related objects at once.** The author names
  the related objects; one note, one author, one text, an entry on each,
  each linked to the others.
- **Canned text blocks are administered and insertable.** Scoped to a
  register, a schema or a group, with the same variable substitution the
  templates use.
- **A mention subscribes.** Naming a principal in an entry notifies them
  and adds them as a watcher over `object-watchers`, which is why this
  change depends on it.

## Consumers

- **dossiq**: renders the timeline, declares the contactmoment kind with
  its channel and direction, and gets the Woo search over entries.
- **portaliq**: the public entries only, over the visibility flag
  `timeline-entry-visibility` carries.
- **keepiq, humaniq, pipelinq, decidiq**: entry kinds and the reference
  pattern with no work per app.
- **integriq**: the raw source of an inbound message is what a disputed
  delivery is proven from.

## ADRs

- ADR-022: one notes and timeline primitive, consumed, not reimplemented.
- ADR-031: an entry kind and its fields are declared, not coded per app.
- ADR-005: entry search resolves access per principal and honours the
  visibility flag; an unresolvable principal sees nothing.
- ADR-003: pinning, closing a follow-up and writing a reference are audit
  facts on the chain.

## Impact

- Extends: `object-interactions` (entry kinds, fields, follow-up, pin, raw
  source, references, canned text) and `unified-search-provider` (entries
  as a searchable kind).
- Affected code: the comments-backed note service, the inbound mail job,
  the search index writer and the provider, the reference resolver.
- Backwards compatible: an entry with no declared kind behaves as a plain
  note, and an instance that declares no reference pattern renders text
  unchanged.
- Size: M.

## Out of scope

- Machine translation of an entry and its reply (C-communication-38). D13
  puts the assistant in hermiq, and translation belongs with it.
- A pasted link rendering as a card (C-communication-20), which is the
  platform reference provider under D9.
- One reply sent to many cases at once (C-communication-49), which is
  `bulk-action-jobs` plus a dossiq surface.
- Acting on a case by replying to its notification with a command
  (C-communication-3) and personal notes hanging on no case
  (C-communication-30). Both `could`, both recorded.
