---
kind: code
---

## Why

`GET /api/settings/search-index` asked pg_indexes once per table in scope. On the demo instance (1,688 tables) the answer took 6.5 s, so dossiq's "Zoekindex" card still showed its spinner when the settings page was read (r31 DqBeheerinstellingen).

## What changes

`SearchIndexMaintenance::indexNamesFor()` reads the index names of many tables in one query per 500 tables; the status endpoint uses it. Same answer shape.
