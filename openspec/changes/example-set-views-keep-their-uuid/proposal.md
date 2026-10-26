# A seeded view keeps its uuid

## Why
Every `--refresh` wrote the saved views of an example set again: it took the old view away and created a new one with a new uuid. Every link to a view broke with it: routes (`?savedView=<uuid>`), menu pins (Pieter's "Termijn deze week" fell out of his menu) and compdiff specs (DqFilterInMenu opened a dead view).

## What changes
- A seeded view gets a uuid derived from its app and key (`ExampleSetViews::uuidFor()`). It is the same on every instance and every install, including a new install after a remove.
- A refresh updates a held view where it is, through `ViewService::update()`, instead of taking it away and writing it again.
- A held view that is gone is written again under its derived uuid.
- The engine-row kinds gain `updatesInPlace()` and `update()`. A kind that does not override them is refreshed as before.
