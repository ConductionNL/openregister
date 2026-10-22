# An export of a selection holds only the selected objects

## Why

A list page that exports "the selected rows" (dossiq's case type list draws
"Selectie exporteren als JSON") needs the export endpoint to take the ids. The
object list endpoint already narrows on `_ids` (a list or a comma-separated
string), but `ExportService::fetchObjectsForExport()` drops every `_`-prefixed
parameter as request plumbing, so `GET .../export?format=json&_ids[]=a&_ids[]=b`
exports every row the caller may read.

## What changes

- `fetchObjectsForExport()` reads `_ids` (an array, or a comma-separated
  string) and hands it to `searchObjects()` as `ids`, which narrows the search
  to those objects. RBAC and multitenancy apply as before, so an id the caller
  may not read is still left out.
- Without `_ids` nothing changes.
