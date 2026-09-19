# Design: activity leaf merged feed

## D-1: merge at query time, no new table

integration-activity already chose query-time storage. The merged feed
queries five sources with the same cursor (time, source, id), takes the top
page across them and returns it. Each source is bounded to the page size, so
the cost is five bounded reads, not a table scan.

## D-2: reads are hidden by default

The audit trail records reads. A timeline of 15 reads and 2 writes is what
dossiq shows today and it hides the writes. The feed excludes read entries
unless the `reads` toggle is on; the toggle is remembered per user.

## D-3: kind vocabulary

`audit`, `file`, `note`, `mail`, `activity`. A row carries the kind, actor,
time, summary and a deep link to the item (the file, the note, the mail
thread) when one exists. Summaries come from each source's own renderer;
the feed does not reinterpret an audit diff.

## D-4: export reuses the formats

CSV through the audit export path; PDF through export-pdf-format with a
feed template. Both take the active filters.

## D-5: kind

Code, in OpenRegister. Consuming apps replace two tabs with one manifest
entry, which is config.
