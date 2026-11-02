# Proposal: example-set-archival-setting

## Why

Dossiq's "My archival reviews" widget reads `/archival/reviews/pending`, which walks the destruction lists in the register and schema the `archival` setting names (`destructionListRegister`, `destructionListSchema`). OpenRegister ships no destruction-list register and both keys are null on every instance, so the widget is always empty, also in the Zuiddrecht example set whose boards draw an open destruction list waiting for Pieter Jansen.

## What changes

- OpenRegister ships `lib/Settings/destruction_list_register.json`: register `destruction-lists` with schema `destructionList` (status, the entries under review with their reviewer and decision, approvals, the decision history). A repair step imports it under its own configuration id, like the e-Depot transfer register.
- OpenRegister's own fragment of an example set MAY declare an `archival` part naming `destructionListRegister` and `destructionListSchema` (by `{{register:}}` / `{{schema:}}`). Install merges only those keys into the `archival` setting and keeps every other key; remove puts back what each key held, unless somebody changed it since. Any other key refuses the set; another app's fragment may not declare the part.
- The Zuiddrecht cast requires the register and names it in its `archival` part, so a fragment (dossiq's) can put a destruction list in it.
