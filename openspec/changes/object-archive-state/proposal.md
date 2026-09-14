---
kind: code
depends_on: []
---

# Proposal: object-archive-state

## Summary

Give an object a state between open and gone. An archived object leaves
the working lists and the search results, refuses writes, keeps its audit
trail and its references, and comes back with one action. It is not
deleted, it is not in the trash, and it is not a destruction date.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q2.33 | Can a closed case be archived, hidden from working views and search, read-only, and restored, without being deleted | partial | M |

From the gap register at `procest/_gaps/` in
ConductionNL/market-intelligence (2026-09-13), owner openregister, slug
`object-archive-state`, opened by the last sweep of the OpenSpec phase.

## Why

The best competitor, verbatim from the register's `best` column: "OTOBO
11.0 and Znuny 7.3: ticket.archive_flag with RestoreFromArchive, driven in
batch 5; Odoo 19.0 action_archive, driven in batch 6
(`_round4/compare/proposed-rows-batch10.md`)". Three products, four
systems, all driven.

The register's `why`: "a state between open and gone, hidden from lists
and search, read-only and restorable, is a property of an object, not of a
case". Its note on dossiq: "`archiefstatus` is ZGW data on the zaak,
defaulting to `nog_te_archiveren` (`lib/Service/ZgwZrcRulesService.php:121`)
and guarded on delete: 'Archived zaken cannot be deleted without the
zaken.geforceerd-verwijderen scope.' (`lib/Controller/ZrcController.php:1265`).
Nothing hides an archived zaak from a list or makes it read-only; the
status is a fact about the archive, not a state of the working view".

OpenRegister already has the two states either side of this one, and
neither is it:

- `deletion-audit-trail` requirement 1 soft-deletes an object by setting
  `@self.deleted`, excludes it from normal queries and offers a trash API
  with restore. The trash is where a mistake goes. An archived object is
  not a mistake and must stay findable on purpose.
- `retention-management` calculates `archiefactiedatum` and destroys on a
  date, under selectielijsten and legal holds. That is when the object
  stops existing, not where it lives while it still does.

So the gap is real and the register cites it correctly: nothing today
takes a finished object out of the working view while keeping it whole.

## What changes

- `POST` and `DELETE /api/objects/{register}/{schema}/{id}/archive`,
  allowed for a caller with `update` on the object, writing
  `@self.archived` (`by`, `at`, `reason`) outside the object's own data.
- Archived objects leave the default object list, the object query and
  every search provider result. `_archived=true` returns them,
  `_archived=any` returns both.
- An archived object refuses every write to its data with a refusal that
  names the archive. Unarchiving is the one write it accepts.
- Reads, audit trail, versions, relations and `$ref` resolution are
  unchanged: an archived object still answers when something points at it.
- A schema declares whether archiving is offered
  (`x-openregister-archive: {"enabled": true}`), so a schema that has no
  finished state does not grow an action nobody uses.
- Archiving and unarchiving each write an audit entry.

## Consumers

- dossiq: an Archive action on a closed case, an Archived lens on Cases,
  and `case.archiveStatus` mapped onto the state rather than duplicated.
  The register's `dossiq_half`: "map archiefstatus onto it, hide archived
  cases from the Cases lenses and offer Restore". Specified in dossiq as
  `archived-cases-leave-the-lenses`.
- decidiq (a closed decision file), pipelinq (a lost lead), keepiq,
  stackiq, opencatalogi: the same action and lens with no code.

## ADRs

- Company ADR-022: one archive primitive, consumed, not reimplemented per
  app.
- Company ADR-031: the schema declares whether archiving applies; no app
  codes the rule.
- openregister ADR-003: archiving and restoring are audit events on the
  hash chain, not silent flag flips.
- openregister ADR-010: archiving needs `update`, not `delete`.

## Impact

- Extends: `object-lifecycle` (the state and its guard) and, by exclusion,
  the default query path.
- Affected code: one migration or one `@self` field, `ObjectsController`,
  the query parser, `RenderObject`, the write guard, the search providers.
- Backwards compatible: an object with no archive marker behaves exactly
  as today, and a query with no `_archived` parameter answers exactly as
  today because nothing is archived yet.
- Size: M.

## Out of scope

- Archiving a tree in one action. Cascade over relations is a second
  change once a caller asks for it.
- Anything about destruction. `retention-management` owns the
  archiefactiedatum, the destruction list and the legal hold, and this
  change does not move a date or a certificate.
- Moving archived rows to another table. The state is a marker; storage
  tiering is a performance change with its own evidence.

## Discovery cluster 29 extension (2026-09-14)

The round 4 discovery sweep in ConductionNL/market-intelligence,
`procest/_round4/discovery/build-plan.md`, names this change as the
vehicle for cluster 29, "Read-only, frozen and locked". Owner
openregister, size M, six candidates: C-case-core-8,
C-tasks-and-phases-35, C-documents-5, C-communication-4,
C-communication-12 and C-communication-13. Two are `must` and one is a
matrix hole: C-documents-5. Passers: 7, all seven driven. dossiq rates
`partial` on two and `no` on four. Decision: none of its own, and it enters
under D6, relevance-led promotion.

**What the cluster asks that archiving does not answer.** Archiving takes
an object out of the working views. These six ask for an object that stays
in them and stops changing, which is a different state and a common one: a
zaak in bezwaar, a dossier awaiting overbrenging, a vastgesteld besluit.

- **A record is frozen read-only while staying visible and searchable**
  (C-case-core-8): openproject, "Administration Work packages, Status,
  app/models/status.rb:82 is_readonly, enterprise", and redmine. dossiq:
  "no status freeze".
- **Work is frozen once the phase that owns it is complete**
  (C-tasks-and-phases-35): xxllnc-zaken, "Case type > Zaakdossier
  (case-type-editor-anatomy.md)".
- **A document marked final can no longer be changed** (C-documents-5,
  `must`, a hole): forgejo, gitea and opencase, "Repository Settings,
  Tags, tag protection, /tag_protections". Archiefwet and a vastgesteld
  besluit both require that what was published stays what was published.
- **A record is closed to further comment while staying readable**
  (C-communication-4): forgejo and gitea, "Issue sidebar, Lock
  conversation, Issue.IsLocked".
- **A note is locked so it can no longer be changed**
  (C-communication-13, `must`): opencase, "Case Journal notes
  (CaseDetail-JournalNotes.md)". An unlockable note is not evidence.
- **An entry is removed from the working view without being destroyed, and
  an authorised reader sees it again** (C-communication-12): otobo, "Show
  and hide deleted articles (article_version.article_delete)". dossiq:
  "document trash exists; a timeline entry cannot be withdrawn".

**What the extension adds.**

- **A frozen state beside the archived one.** Frozen keeps the object in
  lists and search and refuses writes to its data. Archiving keeps
  refusing writes and keeps leaving the lists. They are two states, not one
  with a flag.
- **A freeze may be declared by lifecycle.** A state may declare that
  entering it freezes the object, so a completed phase freezes its
  registration data without anyone remembering to.
- **An immutable property.** A property may be declared immutable once set,
  refused on update whatever the object's state, which is what a final
  document and a vastgesteld besluit need.
- **A record may be closed to new entries while staying readable.** No new
  timeline entries, no new comments, the existing ones unchanged.
- **An entry may be withdrawn.** It leaves the working timeline, stays in
  the record, and an authorised reader sees it with the withdrawal and its
  actor.
- **A note may be locked.** A locked note refuses edits, which is what
  `note-edit-history` calls the iTop-shaped answer, and the lock is an
  audit fact.
