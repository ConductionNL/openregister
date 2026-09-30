---
kind: code
depends_on: []
---

# Proposal: archival-for-apps

## Why

decidiq's change `records-management-archiving` (rows pub-11, pub-18 in decidiq's matrix) archives council dossiers through OpenRegister's archival stack instead of rebuilding it. Read at development 792794c0, three pieces it needs are missing (openregister#4228, Ruben's decision 30 Sep: build them in openregister):

1. Nothing outside OpenRegister can create a destruction list. The routes list, read, approve and reject lists; only the daily `DestructionCheckJob` creates one.
2. `GET /api/archival/certificates` is a stub that always answers an empty list, although `DestructionExecutionJob` stores a `verklaring_van_vernietiging` for every executed list.
3. An app cannot ship selectielijst categories with its register, so the categories its schemas point to (`archive.classification`) resolve to nothing on a fresh install.

## What changes

- `POST /api/archival/destruction-lists` with `{"objects": ["<uuid>", ...]}` creates a destruction list for the objects OpenRegister itself finds eligible. Each uuid is judged by the same rule the daily sweep uses (nominated for destruction, record still live, action date reached, not frozen, no active legal hold, not already on an open list). Refused uuids come back with a reason; the list holds only the eligible ones. The same entry is a PHP service, `DestructionListCreator::createFor()`, for an app that calls it in-process.
- `GET /api/archival/certificates` returns the stored certificates of executed lists, newest first, optionally for one list (`?destructionList=<uuid>`). An executed list whose certificate could not be stored is named under `missing` rather than hidden.
- An app's register import accepts `components.selectionLists`: entries with `category`, `retentionYears`, `action`, `description`, `organisation` (and optional `source`, `version`). They are written as rows of the configured selectielijst register, the store retention actually reads, idempotent by category and organisation.

## Deviation from the issue's wording

The issue asks for `SelectionList` rows. The `SelectionList` entity table (`oc_openregister_selection_lists`) is read by nothing: retention, nomination and certificates all read the selectielijst REGISTER that `SelectielijstImportService` writes and `SelectielijstResolver` reads. Writing the table would ship rows that change no retention decision. So the import writes selectielijst register rows (`categorie`, `archiefnominatie`, `bewaartermijn` as `P<n>Y`, `omschrijving`, `organisatie`, `bron`). The component contract (`components.selectionLists` and its five fields) is as the issue wrote it. Recorded on openregister#4228.

## Rows

No openregister matrix row. decidiq rows pub-11 and pub-18 depend on it; decidiq's lane sets them.
