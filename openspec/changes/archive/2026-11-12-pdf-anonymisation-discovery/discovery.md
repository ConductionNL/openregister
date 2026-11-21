---
status: research
---

# PDF Anonymisation — Discovery Document

## Purpose

This discovery doc captures the research and design analysis for anonymising PDF inputs in OpenRegister's `DocumentProcessingHandler`. It exists to be reviewed by the team **before** committing to a change scaffold, because the FOSS-PHP-native space for PDF text replacement is narrow enough that we want collective agreement on the trade-offs before locking scope.

**Not yet a change.** No proposal, no specs, no tasks. After review, the recommended approach is scaffolded as a real change (or several).

## Context

`DocumentProcessingHandler::anonymizeDocument` dispatches by file extension:

```php
// lib/Service/File/DocumentProcessingHandler.php:132
if (in_array($fileExtension, ['doc', 'docx'], true) === true) {
    return $this->replaceWordsInWordDocument(...);
}
return $this->replaceWordsInTextDocument(...);  // ← PDF lands here
```

`replaceWordsInTextDocument` does `str_ireplace` on the raw binary `$fileNode->getContent()`. For Word documents (text-based) this works; for PDFs (binary ZIP-like container) it **corrupts the file**. Today, anonymising a PDF produces an unopenable output. DocuDesk's `anonymise-output-as-pdf-by-default` wraps the result in a PDF-conversion cascade, which may "succeed" silently while still producing a broken file.

The capability is broken. Operators currently cannot reliably anonymise PDF inputs through the platform.

Two sister changes are already scaffolded (pending review/apply):

- **`office-document-sanitization`** — XML-level surgery on `.docx` and `.odt` to strip comments, tracked changes, metadata, custom XML, hyperlinks. Original preserved; sanitised derivative written to a temp file.
- **`text-extraction-office-completeness`** — deeper PhpWord walker covering tables / lists / headers / footers / footnotes / endnotes, and ODT as a first-class format on both extraction and anonymisation paths.

These two cover DOCX and ODT inputs end-to-end. PDF is not covered by either; this discovery is about closing that gap.

## Hard constraints

Established with the team before this discovery began. Non-negotiable:

1. **No original PII in the output** — the anonymised PDF MUST NOT contain the entity text in any layer (visible, hidden text layer, metadata, content stream). Visual-overlay-only approaches (paint a black rectangle over the text) are ruled out because the underlying text survives.

2. **Identifiable placeholders** — each replaced entity MUST produce a placeholder of the form `[<TYPE>: <id>]` (or compatible) that can be cross-referenced with the grondslagen report. Pure black-bar redaction is ruled out.

3. **Layout preservation** — tables must remain structurally recognisable. Overflow / gaps within cells are acceptable; paragraph wrapping changes are acceptable; loss of table structure is not.

4. **FOSS only** — no commercial dependencies. This rules out Setasign's SetaPDF-Redactor (the only PHP-native library that does true in-place text replacement). Rules out Aspose, commercial iText.

5. **No sidecars** — no separate long-running services to deploy alongside Nextcloud. PHP-side processing only, plus existing in-process Nextcloud app integrations (e.g. NC Office / Collabora when installed).

## Approaches considered

```
┌────────────────────────────────────────────────────────────────────┐
│ A — Visual overlay (FPDI + mPDF black rectangles)                  │
│   ✗ Original text remains in PDF content stream                    │
│   FAILS hard constraint 1                                          │
└────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────┐
│ B — Render-from-extracted-text (smalot → walker → mPDF from text)  │
│   ✗ Loses layout entirely (tables become flat text)                │
│   FAILS hard constraint 3                                          │
└────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────┐
│ C — Commercial library (Setasign SetaPDF-Redactor)                 │
│   ✗ Commercial — fails hard constraint 4                           │
└────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────┐
│ D — Java/Python sidecar (PDFBox, PyMuPDF)                          │
│   ✗ Sidecar architecture — fails hard constraint 5                 │
└────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────┐
│ E — LibreOffice headless CLI exec (direct `soffice` invocation)    │
│   ✗ LibreOffice rarely installed on Nextcloud hosts                │
│   Operational reality rules this out                               │
└────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────┐
│ F — Flatten + re-OCR pipeline                                      │
│   (Ghostscript rasterise + Tesseract OCR overlay)                  │
│   ✗ File size bloat 10-30×                                         │
│   ✗ OCR errors in placeholder text break report traceability       │
│   ✗ Accessibility regression (rasterised PDFs vs text PDFs)        │
│   Functional but degrades document quality unacceptably            │
└────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────┐
│ G — NC Office API ODT round-trip                                   │
│   ✓ FOSS (Collabora LGPL), in-NC integration (no sidecar)          │
│   ⚠ Table fidelity depends on Collabora PDF→ODT conversion;        │
│     for Word-generated PDFs typically good, for positioned-text    │
│     "table-like" layouts variable                                  │
│   ⚠ Latency: 2-5s per file                                         │
│   ⚠ Hard requirement: nextcloud/richdocuments installed            │
│   VIABLE as fallback                                               │
└────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────┐
│ H — SAPP byte-level replacement with Helvetica fallback            │
│   ✓ FOSS (ddn/sapp is LGPL-3.0-or-later)                           │
│   ✓ In-process PHP, no sidecar                                     │
│   ✓ Layout preserved exactly (positions unchanged; only widths     │
│     within affected operators differ)                              │
│   ⚠ Requires custom engineering: filter decoders, font encoding    │
│     resolution, TJ flattening, replacement-injection with font     │
│     switch                                                         │
│   ⚠ Covers ~90-95% of Woo PDFs; remaining cases fall through to G  │
│   PRIMARY CANDIDATE                                                │
└────────────────────────────────────────────────────────────────────┘
```

## Recommended architecture

Two-tier with validation gate.

```
PDF input
   │
   │ smalot/pdfparser probes for text layer
   │   ├── no text layer? → existing ocr-document-scanning
   │   │                    → searchable PDF with text layer
   │   └── has text layer? → continue
   │
   ├──► PATH A — SAPP byte-replace (primary)
   │       │
   │       │ 1. Walk objects via SAPP
   │       │    foreach $obj->get_object_iterator() as $oid => $obj
   │       │
   │       │ 2. For each content-stream object, decode the filter chain
   │       │    (FlateDecode, LZWDecode, ASCII85Decode, ASCIIHexDecode,
   │       │     RunLengthDecode) — multi-encoding support required
   │       │
   │       │ 3. For each font referenced in the page resources,
   │       │    build encoding map:
   │       │      - WinAnsi/MacRoman/Standard → ASCII passthrough
   │       │      - Identity-H/V → parse ToUnicode CMap
   │       │      - Custom /Differences → build per-font table
   │       │
   │       │ 4. Pre-pass: flatten TJ kerning arrays to single Tj strings
   │       │    (loses kerning fidelity; acceptable for Woo)
   │       │
   │       │ 5. Translate substitution map entries through each font's
   │       │    encoding map → per-font byte sequences to search for
   │       │
   │       │ 6. For each Tj/TJ string in content streams:
   │       │      - Match against per-font byte sequences (longest-first)
   │       │      - On match: emit `/F-Helv 11 Tf (replacement) Tj
   │       │                       /Foriginal 11 Tf` (font switch +
   │       │                       Helvetica-rendered placeholder)
   │       │
   │       │ 7. Strip PDF metadata (/Info, /XMP):
   │       │      - dc:title, /Author, /Subject, /Keywords → sentinel
   │       │      - per the sister office-document-sanitization rules
   │       │
   │       │ 8. Post-pass: collapse adjacent-duplicate placeholders
   │       │    "[P:7] [P:7]" → "[P:7]"
   │       │    (arises when variants of one entity matched separately)
   │       │
   │       │ 9. SAPP rewrites the PDF with corrected xref tables
   │       │
   │       └─►  VALIDATION GATE
   │             Re-extract text from the output via smalot.
   │             For each entity in the substitution map: assert
   │             the original text is absent.
   │
   │             ├── PASS → return output PDF (typical path)
   │             └── FAIL → fall through to Path B
   │
   └──► PATH B — NC Office ODT round-trip (fallback)
           │
           │ HARD REQUIREMENT: nextcloud/richdocuments installed.
           │ If not installed, return 422 with explicit setup pointer.
           │
           │ 1. Convert PDF → ODT via IConversionManager
           │      (Collabora handles via its native ODF pipeline)
           │
           │ 2. Sanitise the ODT (sister change office-document-
           │    sanitization, ODT branch)
           │
           │ 3. Walk + substitute (sister change text-extraction-
           │    office-completeness, ODT branch)
           │
           │ 4. Render ODT → PDF via existing pdf-conversion cascade
           │    (DocuDesk change anonymise-output-as-pdf-by-default)
           │
           └─► return output PDF
```

## Why ODT specifically for Path B (not DOCX)

ODT is LibreOffice/Collabora's native format. PDF→ODT and ODT→PDF are direct serialisations of the internal model with no ODF↔OOXML translation layer. For tables specifically — our gating fidelity bar — this matters: complex table metadata (merged cells, column widths, header rows) maps 1:1 between LibreOffice's internal model and ODT. A DOCX intermediate would add a translation step that can simplify these structures.

Architectural cleanliness: the sanitiser and walker sister changes already commit to ODT as a first-class supported format. Path B uses the ODT path of every component; the DOCX path stays for native-DOCX inputs and is orthogonal to the PDF flow.

## Detailed work breakdown — Path A

### 1. Stream filter decoders (MULTI-ENCODING REQUIREMENT)

The PDF spec defines multiple stream filters. Real-world PDFs in the wild use several. The current code assumes everything is binary text (str_ireplace on raw bytes), which is one reason it corrupts PDFs.

Filters that can wrap text content streams:

| Filter            | Description                       | Frequency in Woo PDFs | SAPP support | Notes |
|-------------------|-----------------------------------|----------------------|--------------|-------|
| `FlateDecode`     | zlib/deflate compression          | >95%                 | ✓ (proven)   | Default for modern PDFs |
| `LZWDecode`       | LZW compression                   | <2% (legacy PDFs)    | likely ✓     | Older PDFs, pre-2000-ish |
| `ASCII85Decode`   | binary-to-text wrapper            | <5%                  | ✗ (we build) | Often paired with another filter |
| `ASCIIHexDecode`  | hex encoding wrapper              | <2%                  | ✗ (we build) | Sometimes used for debugging or in non-standard PDFs |
| `RunLengthDecode` | RLE compression                   | <1%                  | ✗ (we build) | Trivial format |
| `Crypt`           | encryption                        | password-protected   | n/a          | Cannot decode without password; raise SanitizationException |

Filters NOT relevant for text streams (image-only):

`DCTDecode` (JPEG), `CCITTFaxDecode` (fax), `JBIG2Decode`, `JPXDecode` (JPEG2000) — these only apply to image XObjects, never text content streams. We do not need to decode these.

**Per the team's direction, all FIVE text-relevant filters MUST be supported.** Even though >95% of Woo PDFs use only FlateDecode, the remaining 5% must not silently fail. Each additional filter is bounded work:

- **FlateDecode** — SAPP-provided. Verify the example code works in practice.
- **LZWDecode** — well-defined; PHP doesn't have a native LZW decompressor but the algorithm is ~50 lines. Several FOSS implementations exist (zen-pdf, php-lzw).
- **ASCII85Decode** — well-defined; ~30 lines.
- **ASCIIHexDecode** — trivial; ~10 lines.
- **RunLengthDecode** — well-defined; ~20 lines.

Filters can be **chained** (e.g. `/Filter [/ASCII85Decode /FlateDecode]` — apply ASCII85 first, then FlateDecode to the result). Our decoder pipeline must process the filter array in order.

Estimated work: **3-4 days** for all four non-FlateDecode decoders + the chaining logic, with unit tests covering each filter individually and combined.

### 2. Font encoding resolver

After stream decompression, the bytes still need a font encoding to map back to readable text. There are several encoding schemes:

| Encoding                    | Behaviour                                         | Frequency in Woo PDFs |
|-----------------------------|---------------------------------------------------|----------------------|
| `WinAnsiEncoding`           | Latin-1-ish; ASCII chars are literal in bytes     | High (Word default)  |
| `MacRomanEncoding`          | similar to WinAnsi but slight differences         | Low (Mac-origin docs)|
| `StandardEncoding`          | PostScript base-14 fonts                          | Medium               |
| Custom `/Differences` array | Standard encoding with per-character overrides    | Medium               |
| `Identity-H` / `Identity-V` | Composite font; 2-byte glyph IDs; need ToUnicode  | Medium-High (subset fonts) |

For each font dictionary in the PDF (`/Font` resources), we determine its encoding:

- **WinAnsi/MacRoman/Standard, no /Differences** — character codes 0x20-0x7F are ASCII-mapped. Search the stream for the literal bytes of the entity text.
- **Standard + /Differences** — build a translation table by applying the /Differences overrides to the base encoding.
- **Identity-H / Identity-V** — composite font. The font dictionary must include a `/ToUnicode` stream (itself FlateDecoded usually). Parse the CMap syntax:
  - `begincodespacerange ... endcodespacerange` — defines the range of valid character codes
  - `beginbfchar ... endbfchar` — explicit character code → Unicode mappings
  - `beginbfrange ... endbfrange` — range-based mappings
  - Result: a forward table (char code → Unicode) and reverse table (Unicode → char code).

Once we have the per-font encoding tables, translating the substitution map entries into per-font byte sequences is straightforward:

```
Substitution map: { "Jan Jansen" → "[PERSON: 7]" }

For font F3 (WinAnsiEncoding):
  search bytes: 0x4A 0x61 0x6E 0x20 0x4A 0x61 0x6E 0x73 0x65 0x6E
  (literal ASCII for "Jan Jansen")

For font F4 (Identity-H, ToUnicode CMap):
  search bytes: <0031> <0061> <006E> <0020> <0031> <0061> ... 
  (glyph IDs that map to the same Unicode characters)
```

Each font in use gets its own search sequence. When matching against a Tj/TJ operator's content, use the font in effect at that operator's position.

Estimated work: **5-7 days** for the CMap parser + per-font encoding resolver + substitution-map translation, with fixture-based tests covering each encoding scheme.

### 3. TJ kerning array flattening (pre-pass)

To handle per-character splits (where text is encoded as `[(J) 5 (a) -3 (n) ...]TJ` to apply per-character kerning), a pre-pass flattens these into single-string Tj operators:

```
Before:
  [(J) 5 (a) -3 (n) 10 ( ) (J) -2 (a) -3 (n) 5 (s) -1 (e) -5 (n)] TJ

After (flatten — discard kerning numbers):
  (Jan Jansen) Tj

For composite (Identity-H) fonts, the strings are 2-byte glyph IDs:
  [<0031> 5 <0061> -3 <006E> 10 <0020> <0031> ...] TJ
  →
  <00310061006E00200031...> Tj
```

The flattening pre-pass:
1. Walks every TJ operator in every content stream
2. Concatenates string elements (in the font's native encoding)
3. Discards numeric (kerning) elements
4. Emits a single Tj operator with the concatenated string

Trade-off: typographic kerning is lost. For body text in Word-generated PDFs, this is visually imperceptible. For typographic publications relying on per-character kerning for visual quality, the difference would be visible. For Woo documents, this is essentially loss-free.

Optional refinement: large kerning numbers (|n| > 200) may represent intentional word-break spacing in justified text. The flattening MAY emit a single space character when encountering these to preserve logical word boundaries. v1 can skip this refinement (just discard all numbers); add later if real Woo PDFs show degradation.

Estimated work: **2-3 days** for the TJ-walker pre-pass with unit tests.

### 4. Helvetica fallback font for replacement injection

Replacements use Helvetica, one of the 14 PDF standard "base fonts" that every PDF reader must render natively without embedding. This eliminates the glyph-subset-availability problem.

Implementation:

1. **Register Helvetica** in the document's resource dictionary (once per document):
   ```
   /F-Replacement << /Type /Font /Subtype /Type1
                     /BaseFont /Helvetica
                     /Encoding /WinAnsiEncoding >>
   ```
   ~120 bytes added to the PDF.

2. **Inject replacements with font switch**:
   ```
   Original:  (Aanvraag van Jan Jansen voor het loket) Tj  [in F3]
   
   Modified: (Aanvraag van ) Tj            [F3 — preserved before match]
             /F-Replacement 11 Tf          [switch font to Helvetica]
             ([PERSON: 7]) Tj              [Helvetica renders all ASCII cleanly]
             /F3 11 Tf                     [restore original font]
             ( voor het loket) Tj          [F3 — preserved after match]
   ```

   The font switch operators (Tf) are inserted before and after the replacement. The replacement text is encoded with WinAnsiEncoding (so ASCII chars are literal bytes — square brackets, colons, digits all render).

3. **Determine the font size to use for Helvetica replacement**: read the current Tf state at the match position, preserve the same size. For multi-size matches (rare — the entity spans multiple Tf operators), use the dominant size.

Estimated work: **3-4 days** for the font registration + replacement-injection logic + per-Tj font-state tracking, with unit tests verifying the output PDF renders correctly in Adobe Acrobat and other readers.

### 5. Variants in substitution map

Already a feature of the existing entity-detection pipeline: when an entity is identified as "Jan Jansen" (a PERSON), the substitution map includes variants — "Jan Jansen", "Jansen", "Jan", "J. Jansen" — all mapped to the same placeholder ID (e.g. `[P:7]`). The map is processed longest-first to avoid partial matches.

For the PDF byte-replace approach, variants handle the most common splits: word-boundary splits where one Tj contains "Aanvraag van Jan " and the next contains "Jansen voor het loket". The full-name variant misses; the surname-only variant matches the second Tj; the first-name variant (if included) matches the first Tj.

When variants produce two adjacent placeholders for one logical entity:

```
Result: (Aanvraag van [P:7] ) Tj ([P:7] voor het loket) Tj
        Visible:  "Aanvraag van [P:7] [P:7] voor het loket"
```

Post-pass collapse (next item) reduces this to a single placeholder.

No new work for the variants themselves — the substitution map already comes built this way. We do need to **ensure all variants of one entity share the same placeholder ID** (already the established convention from `entity-relation-grondslagen`).

### 6. Adjacent-duplicate placeholder collapse (post-pass)

After variants-driven matching, the output stream may contain `[P:7] [P:7]` for a single logical entity. A simple regex post-pass on each Tj/TJ string content:

```
"[P:7] [P:7]"   → "[P:7]"
"[P:7]-[P:7]"   → "[P:7]"
"[P:7]_[P:7]"   → "[P:7]"
"[P:7] [P:8]"   → preserved (different entity IDs)
```

Implementation: regex `(\[[A-Z]+:\s*\d+\])([ \-_])\1` replaced with `\1`. Iteratively applied.

Estimated work: **1 day** including tests.

### 7. PDF metadata sanitisation (parity with sister office-document-sanitization change)

PDFs carry metadata in two locations:

- **`/Info` dictionary** (legacy): /Title, /Author, /Subject, /Keywords, /Creator, /Producer, /CreationDate, /ModDate
- **`/Metadata` XMP stream** (PDF 1.4+): RDF/XML containing the same fields plus additional namespaces (dc:*, xmp:*, pdf:*, etc.)

Sanitisation, parity with the DOCX / ODT rules:

- /Title, /Author, /Subject, /Keywords, /Creator → sentinel `"DocuDesk Anonymisation"`
- /Producer → preserved (it's our PDF library's identifier, not PII)
- /CreationDate, /ModDate → preserved (timestamps, no PII)
- /XMP stream → either rewrite with sentinel values OR remove entirely (decision pending — XMP stream may carry workflow-relevant metadata in custom namespaces)

This is straightforward with SAPP's object iterator — find the /Info dict, mutate fields, mutate or remove /Metadata stream object.

Estimated work: **2 days** including unit tests against real Woo PDF fixtures.

### 8. Stream rewriter (SAPP-provided)

After all modifications, SAPP's `to_pdf_file_s(true)` re-serialises the PDF with corrected cross-reference tables. The `true` argument triggers full rebuild (drops the incremental-update history, producing a clean single-version PDF). This is the structural part SAPP solves for us.

No new work; just use SAPP correctly.

### 9. Validation gate

After Path A produces an output PDF, we MUST verify the result:

1. Re-extract text from the output via smalot/pdfparser.
2. For each entity in the substitution map (full name AND variants), assert the text is absent from the extracted output.
3. If ANY entity text remains:
   - Discard the Path A output.
   - Fall through to Path B (ODT round-trip).
4. If all entities are absent:
   - Return the Path A output as the anonymisation result.
   - Persist sanitisation report including a counter of bytes replaced, fonts touched, filters decoded.

The validation gate is the single most important safety mechanism: it catches every silent-failure mode of byte-replace (encoding mismatches, missed splits, edge-case fonts) by detecting residual entity text in the output.

Estimated work: **1-2 days** including the smalot-based re-extract helper and the fall-through-to-Path-B wiring.

## Detailed work breakdown — Path B (fallback)

### 1. NC Office IConversionManager integration

Nextcloud 30+ exposes `OCP\Files\Conversion\IConversionManager`. Apps register conversion providers; `nextcloud/richdocuments` (Collabora) registers itself for many MIME conversions including PDF↔ODT.

Path B uses this API:

```php
$convertedFile = $conversionManager->convert($pdfFile, 'application/vnd.oasis.opendocument.text');
// $convertedFile is an OCP\Files\File with the converted ODT content

// ... sanitiser + walker run on $convertedFile ...

$pdfOut = $conversionManager->convert($modifiedOdt, 'application/pdf');
```

If `richdocuments` is not installed:
- `IConversionManager` raises an exception ("no provider for this conversion") OR returns false (depending on NC version).
- We catch and return a structured 422 error: "PDF anonymisation requires Nextcloud Office (Collabora). Install the `richdocuments` app and try again."

**Hard requirement**: nextcloud/richdocuments installed.

Estimated work: **2-3 days** for the orchestrator + structured error handling.

### 2. PDF→ODT validation

Verify the conversion produced an ODT we can use:
- File opens cleanly via PhpWord's ODText reader
- Has at least one section with text content
- (Optional) Heuristic table count: smalot extracts tables from the original PDF; PhpWord counts Tables in the ODT — if the original had tables but the ODT has none, surface a warning to the operator.

Estimated work: **1-2 days**.

### 3. Sanitiser + walker invocation (reuses sister changes)

No new work — the ODT branches of `office-document-sanitization` and `text-extraction-office-completeness` already handle the in-memory operations.

### 4. ODT→PDF output (reuses pdf-conversion cascade)

No new work — DocuDesk's existing `pdf-conversion` cascade (from `anonymise-output-as-pdf-by-default`) handles ODT→PDF as one of its default cases. Path B's final step slots into this cascade unchanged.

## Open questions for team review

These are the points I'd like the team's input on before scaffolding.

### Q1. Two-tier (A+B) or single-tier (B only)?

Path A is the cleaner outcome (layout-preserving, fast, no operational dependency on richdocuments). Path B is more reliable for edge cases. Tier options:

**Option α (recommended): Both, with validation gate triggering fallback**
- Path A primary, Path B fallback when validation fails
- ~95% of PDFs get the fast happy path
- Edge cases get the slower but more robust round-trip
- More implementation work; more architectural surface

**Option β: Path B only**
- Skip the byte-replace work entirely
- Every PDF goes through the NC Office round-trip
- ~3 weeks less implementation work
- Layout fidelity degraded for ~95% of PDFs that didn't need the round-trip
- Hard requirement on richdocuments for all PDF anonymisation

**Option γ: Path A only**
- Skip the fallback
- ~5-10% of PDFs would silently produce broken or PII-leaky output
- Validation gate catches this — but with no fallback, the operator just sees an error
- Eliminates the richdocuments hard-requirement
- Probably unacceptable for a privacy operation

### Q2. Placeholder format change (subset compatibility safety net)

Current format: `[<TYPE>: <id>]` — e.g. `[PERSON: 7]`.

If Helvetica fallback is the strategy, ALL characters are guaranteed renderable; current format is fine.

If we want a safety net in case Helvetica fallback proves unreliable on some readers, an alternative format using only universally-subset-safe characters would be:

- `(<TYPE>: <id>)` — parentheses universally available, but visually overloaded (parens already common in body text)
- `<TYPE>_<id>` — underscore + alphanumeric only; pure ASCII safe; less visually distinctive
- `<TYPE>-<id>` — hyphen + alphanumeric; similar trade-off

Decision needed: stick with `[PERSON: 7]` (Helvetica guarantees rendering) or switch to a subset-safe format. If switching, this is a coordinated change across DOCX walker + ODT walker + grondslagen-summary templates.

### Q3. /XMP metadata stream — sanitise in place or remove entirely?

PDFs have two metadata locations: legacy `/Info` dict and modern `/XMP` stream. The `/Info` rules are clear (replace fields with sentinel). For `/XMP`:

**Option α**: Parse the RDF/XML, replace dc:* / xmp:* / pdf:* fields with sentinel, preserve custom namespaces (workflow metadata).

**Option β**: Remove the `/XMP` stream entirely. Cleanest from a PII perspective; loses any custom-namespace workflow metadata.

I'd lean toward α (parity with the office-document-sanitization change's metadata rules — replace, don't remove) but option β is defensible.

### Q4. Validation gate failure handling

When Path A produces an output but validation finds residual entity text, the default is fall-through to Path B. Alternative behaviours:

- **Abort entirely**: return an error to the operator. Safer (no chance of producing a bad output), but loses the benefit of the fallback.
- **Surface details**: tell the operator WHICH entities failed Path A so they can decide whether to accept the Path B output or escalate.

### Q5. Test fixture sourcing

The validation work needs real-world Woo PDFs covering:
- Word-generated text PDFs (the common case)
- Older government-form-tool PDFs (custom encodings)
- Scan-with-OCR PDFs (text layer from OCR — sometimes oddly encoded)
- PDFs with tables (positioned text vs structured tables)
- Multi-page PDFs with mixed font usage

Sourcing real Woo PDFs is bounded by privacy concerns (they contain PII by definition). Options:

- Use publicly-released Woo decisions (rijksoverheid.nl publishes anonymised versions; we'd use the unanonymised internal versions if available)
- Synthesise fixtures via Word/LibreOffice with sample names
- Reuse fixtures from the sister office-document-sanitization change (extended)

### Q6. Empirical spike before committing

Before scaffolding the change, a 2-3 day spike on real Woo PDFs would answer:

- What filter chains appear in practice (FlateDecode only? Mix?)
- What font encodings dominate (WinAnsi only? Identity-H mixed in?)
- How often do per-character splits actually occur in Word-generated PDFs?
- What fraction of typical entities split across operators vs single-string?

The spike output is essentially a measured version of this discovery. It de-risks the entire path A engineering effort.

## Estimated total work (Path A + Path B both built)

```
PATH A:
  Stream filter decoders (5 filters + chaining)       3-4 days
  Font encoding resolver (incl. ToUnicode CMap)       5-7 days
  TJ flattening pre-pass                              2-3 days
  Helvetica registration + replacement injection      3-4 days
  Adjacent-placeholder collapse                       1   day
  PDF metadata sanitisation (/Info + /XMP)            2   days
  Validation gate (re-extract + fall-through)         1-2 days
  Test fixtures + integration tests                   3-4 days
  Path A total                                        20-27 days  (~4-5 weeks)

PATH B:
  IConversionManager orchestrator                     2-3 days
  PDF→ODT validation + table-count probe              1-2 days
  Sanitiser + walker invocation                       0   days  (reuses sister changes)
  ODT→PDF cascade                                     0   days  (reuses anonymise-output-as-pdf)
  Hard-requirement error handling                     1   day
  Test fixtures + integration tests                   2-3 days
  Path B total                                        6-9 days   (~1-2 weeks)

GRAND TOTAL (both paths):                            26-36 days  (~5-7 weeks)
```

For comparison: Setasign SetaPDF-Redactor + licensing would be ~1 week implementation, but is ruled out by the FOSS constraint.

## Risks and mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| SAPP doesn't handle some filter chain in a real PDF | Medium | Spike empirically; add fallback decoders explicitly per Q1 above |
| ToUnicode CMap parser misses some CMap syntax (begincidchar, multi-byte) | Medium | Test against real fixtures during spike; v1 supports common syntax; document gaps |
| Path A produces an output that LOOKS clean but has subtle font-rendering artifacts | Low | Validation gate uses smalot to re-extract; visual inspection in PR reviews |
| Some PDF readers handle Helvetica font switch unexpectedly | Low | Helvetica is a PDF standard base font; should work everywhere; test against Acrobat, evince, browser PDF viewers |
| NC Office API contract changes between Nextcloud versions | Low-Medium | Pin to NC 30+ where IConversionManager is stable; document in change |
| Validation gate gives false positive (entity text shows in stream but is decorative not actual) | Very Low | Real edge case; document gracefully; manual review path |
| Both paths fail on a specific PDF | Low | Return clear 422 to operator with diagnostics; document escalation flow |

## Recommended next step

After team review of this discovery doc:

1. **Empirical spike** (2-3 days) — run SAPP against ~10 real Woo PDFs covering the variety in Q5; measure filter/encoding/split distributions; document findings.
2. **Decisions on Q1-Q4** captured in a follow-up section of this doc.
3. **Scaffold the change(s)** as openspec changes — likely one for Path A primary and one for Path B fallback, depending on Q1.

## Appendix: References

- [PDF Reference, sixth edition (Adobe)](https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/pdfreference1.7old.pdf) — sections on content streams (5.3), filters (3.3), font encodings (5.5), text-show operators (5.3)
- [ddn/sapp on GitHub](https://github.com/dealfonso/sapp) — the SAPP library used for object-level access and PDF rebuild
- [ddn/sapp on Packagist](https://packagist.org/packages/ddn/sapp) — LGPL-3.0-or-later, 73K installs
- Sister OpenSpec changes:
  - `office-document-sanitization` (just scaffolded — DOCX/ODT structural cleanup)
  - `text-extraction-office-completeness` (just scaffolded — walker depth + ODT first-class)
  - `anonymise-output-as-pdf-by-default` (existing — DocuDesk-side PDF output cascade)
  - `ocr-document-scanning` (existing — for image-only PDFs)
- Setasign SetaPDF-Redactor — commercial, ruled out by FOSS constraint; included for comparison
