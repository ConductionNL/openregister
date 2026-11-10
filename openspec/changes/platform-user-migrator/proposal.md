---
kind: code
depends_on: []
---

# Proposal: platform-user-migrator

## Summary

`OCP\User\Migration\IMigrator` is what puts an app's data into a user's
export when they leave, and back when they arrive. A caseworker who moves
to another gemeente takes their files and their calendar and leaves every
object behind, because nothing offers them. This change exports and
imports what a user personally holds on objects, and deliberately leaves
the objects themselves where they belong.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`: the seventh of the ten is the user migrator, and the
consequence the lane names is that a zaak "does not leave with the person
who owned it". Candidate C-access-and-privacy-57 measures the same thing:
nextcloud-deck, "lib/UserMigration/DeckMigrator.php", with dossiq `no` and
"zero hits".

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface.

## What openregister implements generically

- One `IMigrator` that exports, for a user, the things that are theirs
  rather than the organisation's: their saved views, their favourites and
  recents, their watches, their notification preferences and overrides,
  their personal API tokens' metadata without the secrets, and their own
  notes and timeline entries as a readable archive.
- **Objects are not exported.** A zaak belongs to the gemeente, and a
  migrator that carried it would be an exfiltration path with an export
  button. The export says so, in the export itself, rather than being
  silently incomplete.
- **Import restores what can be restored** and reports what it did not.
  A saved view over a register the new instance does not have is reported,
  not invented.

## What a leaf app declares

Nothing. A leaf app whose per-user state lives in the platform's own
stores is carried by this migrator without registering anything, which is
the point of specifying it once.

## What exists and what is missing

A search of the openregister openspec tree on 2026-09-14 returns no hits
for `IMigrator` in `specs/` or `changes/`. `favourites-and-recent`,
`object-watchers`, `saved-search-views`, `view-group-share` and
`notificatie-engine` each hold per-user state, and none of it leaves with
the user. `data-subject-rights-across-the-instance` specifies the subject's
own export, which answers a different question: what an organisation holds
about a person, rather than what a person holds in the product.

## Impact

- Extends: a new `platform-user-migration` capability.
- Affected code: a migrator class and its registration, the per-user state
  readers, the import writers.
- Backwards compatible: nothing changes until a user export runs.
- Size: M.

## Out of scope

- Exporting objects. They belong to the organisation, not to the account.
- Moving ownership of work to a colleague when somebody leaves
  (C-access-and-privacy-56), which is a dossiq act under cluster 36.
- The data subject's own export under the AVG, which
  `data-subject-rights-across-the-instance` carries.
