# Activity leaf: one merged feed per object

## Why

Round 2 of the dossiq competitor analysis (row A05 in
`concurrentie-analyse/procest/_round2/compare/findings.md`, placement section
3, decision D10): every competitor shows one timeline on the case, in reverse
order, with actor and time, spanning status changes, documents, notes and
mail. OpenCase and GZAC each have a Log tab
(`opencase/round2/pages/CaseDetail-Log.md`, `valtimo/round2/pages/CaseDetail-Log.md`);
Zaaksysteem's Tijdlijn adds filters, a date range and an export
(`xxllnc-zaken/round2/pages/Case-Tijdlijn.md`).

dossiq's CaseDetail sidebar shows the same OpenRegister audit rows twice
(History and version history), 15 of 17 rows are reads, and documents, mail
and notes do not appear (`_round2/dossiq-baseline/case-detail-anatomy.md`).
The activity leaf already blends NC Activity rows marked `[or:{uuid}]`
(integration-activity, Blended Feed) but the blend covers the Activity app
only; audit entries, file events, notes and linked mail are separate lists.
A merged feed is the same need in every app that mounts a detail page.

## What changes

- The activity leaf's feed for one object merges five sources: the object's
  audit trail (writes only by default), file events on the object, notes,
  mail linked to the object, and NC Activity rows, in one reverse
  chronological list with actor, time, kind and a one-line summary.
- Filter chips per kind, a date range, and a toggle to include reads.
- Export of the filtered feed as CSV or PDF through the existing export
  formats.
- The feed is a `tab` and a `widget` surface so a manifest places it; a
  detail page that mounts it drops its separate audit and version tabs.

## Who benefits

dossiq (CaseDetail sidebar), zaakafhandelapp, humaniq, keepiq, opencatalogi,
every app with a detail page.

## Impact

- Affected specs: integration-activity (delta).
- Affected code: `lib/Service/Integration/Providers/ActivityProvider.php`
  (merge), the interaction timeline API (object-interactions, Unified
  Interaction Timeline API) as one of its sources, the activity leaf's Vue
  surfaces.
- Backwards compatible: the NC Activity-only feed remains the default when
  the consuming manifest asks for it.
