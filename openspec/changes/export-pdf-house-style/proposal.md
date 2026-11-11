---
kind: code
depends_on: []
---

# Proposal: export-pdf-house-style

## Summary

A municipality's PDF exports from OpenRegister carry its house style: its
logo at the top, its fonts, and its footer line with the organisation name and
the accessibility and privacy links. The values come from thematiq's document
house style profile, so the administrator sets them once for every document
the instance generates. Without thematiq, the export looks as it does today.

## Halves this closes

This is the OpenRegister half of thematiq's merged change
`surfaces-document-house-style` (thematiq `development` fd992ea). It has no row
in OpenRegister's matrix; the owner moves pass of 28 Sep 2026 handed it here.
Thematiq writes: "Sibling halves, not in this repository: filinq seeds and
refreshes its `huisstijl` object from the profile ...; OpenRegister adds the
profile's logo, fonts and footer to `ExportService::exportToPdf()`
(`lib/Service/ExportService.php:332` at openregister `555af721`)."

The demand is tender demand. Thematiq's proposal: "Three tenders ask that
documents the system generates carry the house style: logo, cover, footer and
fonts. Hilversum (TenderNed 404703, VTH), the FUMO (415897) and the BUCH
municipalities (298070, several house styles, one per municipality) all name
it." Its Risks: "A sibling that never reads the profile leaves the tender
unmet."

## What changes

- `ExportService::exportToPdf()` reads the document style profile for the
  requesting user from thematiq when thematiq is installed.
- The PDF shows the profile's logo in the header, uses the profile's body and
  heading fonts when they are custom fonts it can embed, colours the table
  header with the profile's primary colour, and prints the footer lines on
  every page beside the page number.
- Without thematiq, or with a profile that cannot be read, the export is the
  current layout. The fallback is logged once per request.

## Out of scope

- Cover pages. The export is a table report; the cover is for letters, which
  are filinq's.
- Other export formats. CSV and Excel carry no house style.

## Impact

- `lib/Service/ExportService.php` (`exportToPdf()` at `:332`, the stylesheet at
  `:545-550`, Dompdf options and `page_text()` at `:560-590`).
- New `lib/Service/Export/DocumentStyleReader.php` (the duck-typed lookup).
