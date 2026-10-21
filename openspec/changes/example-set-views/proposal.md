# Proposal: example-set-views

## Why

Dossiq's search page draws a saved search with a count alert ("Opgeslagen zoekopdracht", DqZoekresultaten). A saved
view lives in OpenRegister's view table, not in a register, so no example-set fragment could seed one. The jeugdwet
example case is handled by a jeugdteam regisseur, Fatma Kaya, who was not in the cast.

## What changes

- A fragment may declare `views`: a key and the view's fields (name, description, owner, isPublic, query, presentation,
  alert). The owner is one of the set's own accounts. Checked before anything is written, written once through the view
  service (which validates presentation and alert), recorded, proved, and deleted on remove.
- Tasks and views share one check, write, prove and delete path (`ExampleSetEngineRows`, `ExampleSetEngineKinds`); a
  kind this OpenRegister cannot write refuses its rows instead of skipping them.
- The cast gains Fatma Kaya (regisseur jeugdteam, group Sociaal domein).
