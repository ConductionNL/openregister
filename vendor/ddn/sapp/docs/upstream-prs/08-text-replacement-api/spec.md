# Spec — `replaceTextInDocument()` API

## Requirements

### REQ-01. `PDFDoc::replaceTextInDocument()` MUST exist with the documented signature

```php
public function replaceTextInDocument(array $substitutions, array $options = []): array
```

- `$substitutions`: `array<string $needle, string $replacement>`. Needles MUST be non-empty Unicode strings. Replacements MUST contain only characters representable in the `replacement_font`'s encoding (per default: ASCII printable).
- `$options`: optional dictionary; defaults per `design.md` D2.
- Returns `array` with diagnostic fields per design D3.

**Scenario: signature exists**

GIVEN a PDFDoc loaded from a real PDF
WHEN the consumer calls `$doc->replaceTextInDocument(['Jan' => '[P:7]'])`
THEN the call returns without a method-not-found error
AND the return value is an array with diagnostic keys

### REQ-02. Replacement MUST work end-to-end on WinAnsi-encoded body text

GIVEN a PDF with a single page containing `"Aanvraag van Jan Jansen voor het loket"` in a WinAnsi-encoded font
WHEN `replaceTextInDocument(['Jan Jansen' => '[PERSON: 7]'])` is called
THEN the output PDF re-extracts to text containing `[PERSON: 7]`
AND the output PDF re-extracts to text NOT containing `Jan Jansen`
AND `$result['replacements_made']` is 1

### REQ-03. Replacement MUST work on Identity-H composite fonts via the ToUnicode CMap

GIVEN a PDF with `"Jan Jansen"` in an Identity-H subset font (no glyphs for `[`, `]`, `:`)
WHEN `replaceTextInDocument(['Jan Jansen' => '[PERSON: 7]'])` is called
THEN the output PDF re-extracts to text containing `[PERSON: 7]`
AND `Jan Jansen` is absent from the output
AND the font switch to Helvetica is visible in the output content stream

### REQ-04. Replacement font registration MUST be idempotent across pages

GIVEN a multi-page PDF
WHEN `replaceTextInDocument()` is called
THEN `/F-Replacement` (or the configured `replacement_font` resource name) MUST be added to every page's `/Resources/Font` dictionary
AND repeated registration on the same document MUST NOT duplicate the entry

**Scenario: 5-page PDF gets one Helvetica resource per page**

GIVEN a 5-page PDF
WHEN replaceTextInDocument runs
THEN the placeholder font resource appears once per page Resources dict (5 total) — not 5N for N replacements

### REQ-05. Encrypted PDFs MUST throw `TextReplacementException`

GIVEN a password-protected (encrypted) PDF
WHEN `replaceTextInDocument()` is called
THEN `TextReplacementException` is thrown
AND the exception message names "encrypted PDF"
AND no partial output is produced

### REQ-06. Unmatched substitutions MUST be surfaced in diagnostics

GIVEN a substitution `['Mystery' => '[NONE]']` whose needle doesn't appear in the document
WHEN `replaceTextInDocument()` is called
THEN `$result['unmatched_substitutions']` contains `'Mystery'`
AND no part of the document is modified to remove this needle (since it wasn't there)

### REQ-07. Fonts without ToUnicode MUST be skipped (or throw, per option)

GIVEN a PDF containing a composite font without a `/ToUnicode` stream
AND a substitution whose needle requires that font's encoding
WHEN `replaceTextInDocument()` runs with the default `skip_unresolvable_fonts: true`
THEN the font's text is left unchanged
AND `$result['fonts_skipped']` contains the font name + reason "no ToUnicode CMap"

When `skip_unresolvable_fonts: false`:

THEN `TextReplacementException` is thrown with reason "font_unresolvable"

### REQ-08. Adjacent-placeholder collapse MUST work when enabled

GIVEN substitutions `['Jansen' => '[PERSON: 7]', 'Jan' => '[PERSON: 7]', 'Jan Jansen' => '[PERSON: 7]']`
AND a PDF containing `"Jan-Jansen"` (hyphenated)
WHEN `replaceTextInDocument()` runs with `collapse_adjacent_duplicates: true`
THEN the output's re-extracted text contains a single `[PERSON: 7]` (not `[PERSON: 7]-[PERSON: 7]`)

### REQ-09. Original layout MUST be preserved

The output PDF MUST render with:

- Tables structurally intact (cells, borders, row/column layout unchanged).
- Paragraph boundaries unchanged.
- Page breaks unchanged.

Minor visual shifts within a single cell (placeholder width differs from original) ARE acceptable.

**Scenario: 3×3 table with entity replacement**

GIVEN a PDF with a 3×3 table where one cell contains an entity
WHEN replacement runs
THEN the output PDF still renders as a 3×3 table
AND no rows / columns / borders are lost

### REQ-10. Output PDF MUST be valid

The output of `replaceTextInDocument()` followed by `to_pdf_file_s(true)` MUST open without errors in standard PDF readers (Adobe Acrobat, Firefox/Chrome built-in viewers, evince) and pass `pdfinfo` validation.

**Scenario: output is a valid PDF**

GIVEN any successful replacement
WHEN the output bytes are written via `to_pdf_file_s(true)` and re-loaded
THEN `pdfinfo` reports no errors
AND `PDFDoc::from_string` re-parses the output without errors

### REQ-11. Image-only filters in non-text streams MUST be preserved unmodified

The API MUST NOT modify content of streams whose filter is `/DCTDecode`, `/CCITTFaxDecode`, `/JBIG2Decode`, or `/JPXDecode`. Those streams are image content; no text to replace.

**Scenario: JPEG image stream untouched**

GIVEN a PDF with a body containing text + an embedded JPEG image
WHEN replacement runs
THEN the JPEG image bytes in the output are byte-identical to the input
AND only the text content streams are modified
