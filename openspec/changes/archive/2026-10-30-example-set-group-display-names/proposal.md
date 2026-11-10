# Proposal: example-set-group-display-names

## Why

BOARD-SYNC: the groups `dossiq-sociaal-domein` and `behandelaars` have no display name on :8080 (they show their id). dossiq's fragment declares their names, but dossiq's repair steps made the groups first, so the example set kept them as they were and never wrote the name.

## What changes

A declared group that already exists and shows only its id (no name of its own) gets the declared display name; the install records the name it had, and remove puts it back while the group still carries the set's. A group somebody named is left alone.
