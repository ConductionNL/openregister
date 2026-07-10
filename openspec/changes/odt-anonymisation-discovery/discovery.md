---
status: research
---

# ODT Anonymisation Writeback — Discovery Document

## Purpose

This discovery captures the research for anonymising **OpenDocument Text (`.odt`)** inputs
end-to-end through the DocuDesk + OpenRegister anonymisation pipeline. Unlike the
PDF case (see sister `pdf-anonymisation-discovery`), the FOSS-PHP-native tooling here
is mature — PhpWord ships both an `ODText` reader and an `ODText` writer — so this doc
is short: the decision space is narrow and the main value is (a) recording a **live
privacy bug** and (b) picking a writeback strategy.

**Not yet a change.** No proposal, no specs, no tasks. After review, the recommended
approach is scaffolded as real change(s) — a backend writeback fix in OpenRegister and
a paired frontend enablement in DocuDesk.

## Context — ODT is *half* supported today

The anonymisation pipeline spans two apps. DocuDesk orchestrates; OpenRegister does the
actual document reading and writing.

```
                    DocuDesk                          OpenRegister
                    ────────                          ────────────
 READ text ──────── AnonymizationService ───────────▶ TextExtractionService
 (entity detection)                                    └─ extractWord() → ODText reader   ✅ DONE
                                                          (text-extraction-word-completeness)

 WRITEBACK ──────── FileService->anonymizeDocument ──▶ DocumentProcessingHandler
 (replace text                                          └─ replaceWords()
  in-place,                                                ├─ doc/docx → Word path (PhpWord) ✅
  outputFormat:                                            ├─ pdf      → PdfTextReplacer      ✅
  "preserve")                                              └─ *else*   → raw-text fallback    ❌ .odt lands here

 CONVERT ────────── PhpWordBackend (ODText reader) ──▶ HTML → mPDF → PDF/A                  ✅ works
 to PDF             OfficeAppBackend (Collabora)

 GENERATE ───────── CorrespondenceService                                                  ❌ no ODT out
 (templates)        VALID_FORMATS = pdf|docx|html|email
```

Three independent axes, three different states for ODT:

| Capability | Location | ODT today |
|---|---|---|
| Read ODT text (detection) | OR `TextExtractionService::extractWord` / `isWordDocument` | ✅ done (`text-extraction-word-completeness`) |
| Convert ODT → PDF | DocuDesk `PhpWordBackend` (`READER_BY_EXT['odt'] = 'ODText'`), `OfficeAppBackend` | ✅ works |
| **Anonymise & keep ODT** (`outputFormat: "preserve"`) | OR `DocumentProcessingHandler::replaceWords` | ❌ **broken** — this doc |
| Generate ODT from templates/letters | DocuDesk `CorrespondenceService`, `TemplateRenderer` | ❌ out of scope here |

The read side was deliberately closed by `text-extraction-word-completeness` (tasks all
`[x]`), which scoped writeback *out*. This discovery closes the writeback side.

## The live bug — silent no-op / corruption on ODT anonymise

`DocumentProcessingHandler::replaceWords()` dispatches by extension
(`lib/Service/File/DocumentProcessingHandler.php:234`):

```php
if (in_array($fileExtension, ['doc', 'docx'], true) === true) {
    return $this->replaceWordsInWordDocument(...);   // PhpWord object-model roundtrip
}
if ($fileExtension === 'pdf') {
    return $this->replaceWordsInPdfDocument(...);     // PdfTextReplacer
}
return $this->replaceWordsInTextDocument(...);        // ← .odt lands here (raw-byte str_ireplace)
```

`.odt` is **not** in the `['doc','docx']` branch, so an ODT input falls through to
`replaceWordsInTextDocument`, which does `str_ireplace` on the raw file bytes. An ODT is a
**ZIP container** — the text lives (usually deflated) inside `content.xml`. Two failure
modes, both bad:

1. **Compressed entries (normal case):** the entity/placeholder strings are not present in
   the byte stream, so nothing is replaced → the "anonymised" `.odt` is
   **byte-identical to the original**. A silent PII leak that reports success.
2. **Stored (uncompressed) entries:** a replacement changes byte length → **corrupt ZIP /
   broken CRC**, an unopenable file.

This is not gated anywhere. In `outputFormat: "preserve"` mode the leaky/corrupt file is
the delivered output. In `pdf`/`pdf-only` mode the corrupt case is *usually* caught when
conversion fails — but the byte-identical case would render a PDF from the **un-redacted**
ODT. This mirrors exactly the PDF corruption noted in `pdf-anonymisation-discovery`; ODT
was simply never separated from the raw-text fallback.

**Severity:** correctness + privacy. Independent of whether we build full ODT support, the
unsupported path must stop silently leaking (see Q3 / the interim guard).

## Hard constraints

Same privacy bar as the sister PDF/office work:

1. **No original PII in the output** — no entity text in any layer of the written ODT.
2. **Identifiable placeholders** — `[<TYPE>: <id>]` form, cross-referenceable with the
   grondslagen report (already produced by `buildEntityReplacements`).
3. **Layout preservation** — tables/headers/footers remain structurally recognisable.
4. **FOSS only** — no commercial deps. (PhpWord `ODText` is already vendored; satisfied.)
5. **No sidecars** — PHP in-process, plus existing in-NC Office integration when installed.

## What the existing docx path already gives us for free

`replaceWordsInWordDocument()` (`DocumentProcessingHandler.php:1109`) is **almost
format-agnostic**:

- It loads via `IOFactory::load($tempFile)` (auto-detect — already reads ODT).
- It walks the PhpWord **object model** generically: `getText()`/`setText()`, tables
  (`getRows()`→`getCells()`), lists (`getItems()`), headers/footers, nested elements.
  This traversal is identical for DOCX and ODT.
- The **only** Word-specific parts are: the final writer
  (`IOFactory::createWriter($phpWord, 'Word2007')->save(...)`, line 1239) and two
  PhpWord roundtrip workarounds — the `Style\Numbering` `TypeError` fix (lines 1226–1235)
  and the soft-line-break `<w:br/>` fix (lines ~1300+). Both are **Word2007-writer
  specific** and must be gated off the ODT path.

So the cheapest correct fix reuses the entire object-model traversal and swaps the writer.

## Approaches considered (writeback)

```
┌────────────────────────────────────────────────────────────────────┐
│ A — PhpWord object-model roundtrip, ODText writer  (RECOMMENDED v1)  │
│   Read ODT (ODText) → walk model → setText() → write ODText          │
│   ✓ FOSS, in-process, already vendored                              │
│   ✓ Reuses the existing docx traversal verbatim                     │
│   ✓ Consistent with how docx is handled today                      │
│   ⚠ PhpWord ODText writer less mature than Word2007 — some          │
│     formatting fidelity loss possible (styles, complex tables)      │
└────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────┐
│ B — Direct content.xml surgery inside the ODT zip  (higher fidelity)│
│   Unzip → string/DOM replace in content.xml (+ styles.xml for       │
│   headers/footers) → rezip                                          │
│   ✓ Exact formatting preservation (bytes untouched except text)    │
│   ✗ Entities can split across <text:span> runs → needs XML-node-    │
│     aware replacement (the "split run" problem the object model     │
│     sidesteps). Non-trivial.                                        │
│   → connects to the deferred positional-anonymisation idea          │
└────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────┐
│ C — NC Office (Collabora) ODT roundtrip via IConversionManager      │
│   ✓ Highest fidelity when richdocuments installed                  │
│   ✗ Hard dependency on richdocuments; overkill for a native ODT    │
│     that PhpWord can already open. Better as the PDF-path fallback  │
│     (see pdf-anonymisation-discovery Path B), not the ODT primary.  │
└────────────────────────────────────────────────────────────────────┘
```

## Recommended architecture

**v1 = Approach A**, mirroring the docx path exactly, with a validation gate borrowed from
the PDF discovery's safety pattern.

```
ODT input
   │
   ├─► replaceWords(): route 'odt' into the Word branch alongside doc/docx
   │
   ├─► replaceWordsInWordDocument():
   │      1. IOFactory::load()                 (auto-detects ODText)
   │      2. walk model + setText()            (UNCHANGED — shared with docx)
   │      3. gate the Word2007-only workarounds (Numbering / soft-break)
   │         to the docx writer only
   │      4. pick writer by input extension:
   │            odt  → createWriter($phpWord, 'ODText')
   │            else → createWriter($phpWord, 'Word2007')
   │      5. save to temp, write back as .odt
   │
   └─► VALIDATION GATE (recommended, parity with PDF discovery)
         Re-extract text from the output via extractWord().
         Assert every entity's original text is absent.
         ├── PASS → return the anonymised .odt
         └── FAIL → best-effort policy: record residuals
                    (getLastResidualEntities) so DocuDesk can warn,
                    exactly as the docx path already does.
```

Renaming note: `replaceWordsInWordDocument` becomes a slight misnomer once it also handles
ODT. Consider renaming to `replaceWordsInOfficeDocument` (or leaving it and documenting the
extended scope) — a cosmetic decision for the change, not a blocker.

## Frontend enablement (paired DocuDesk change)

The upload widget is the lone frontend blocker. All in
`docudesk/src/views/anonymization/AnonymizationWidget.vue`:

| Line | Current | Change |
|---|---|---|
| 37 | `accept=".docx,.txt,.pdf,.eml,…"` | add `.odt` + `application/vnd.oasis.opendocument.text` |
| 134 | `ALLOWED_EXTENSIONS = ['docx','txt','pdf','eml']` | add `'odt'` |
| ~138 | `ALLOWED_MIME_TYPES` | add `application/vnd.oasis.opendocument.text` |
| 27, 348 | copy: *"Only Word (.docx), PDF or TXT files are supported"* | reword to include ODT |

The rest of the frontend is already ODT-aware: `FolderFilesNavigation.vue:108` picks the
Word icon for `.odt`, and `Settings.vue:86` already advertises the fallback tier as
handling "DOC/DOCX/**ODT**/RTF/HTML/TXT". The in-viewer preview
(`fileViewerService.js`) renders only `.docx` via mammoth — ODT preview is a **separate,
optional** nicety, not a blocker for anonymisation.

This is a cross-app pair with a **soft dependency** (same framing as `text-extraction-eml`
↔ DocuDesk): OR provides the writeback capability; DocuDesk enables the upload. They can
land independently — the frontend change without the backend fix would surface the current
bug to more users, so the **backend fix should land first** (or the interim guard, Q3).

## Work breakdown

```
BACKEND (OpenRegister) — Approach A
  Route 'odt' into the Word branch in replaceWords()          ~0.5 day
  Writer selection by extension in replaceWordsInWord*         ~0.5 day
  Gate Numbering / soft-break workarounds to docx writer       ~0.5 day
  Validation gate (re-extract via extractWord + residuals)     ~1 day
  Unit tests (PhpWord-generated ODT fixture: paragraph, table,
    header/footer; assert entities removed + file re-opens)    ~1-2 days
  Backend total                                                ~3-4 days

FRONTEND (DocuDesk)
  AnonymizationWidget allowlist (accept/ext/mime) + copy       ~0.5 day
  i18n (NL + EN) for reworded strings                          ~0.5 day
  Unit test for the split-accepted/rejected helper w/ .odt     ~0.5 day
  Frontend total                                               ~1-1.5 days
```

## Open questions for team review

### Q1. v1 writeback strategy — A (PhpWord) or B (zip surgery)?
Recommendation: **A** for v1 (cheap, consistent with docx, no new deps), accept documented
fidelity loss, keep PDF-output as the recommended default for high-fidelity needs. Revisit
B only if real ODT fixtures show unacceptable formatting loss — B also naturally hosts the
deferred **positional anonymisation** idea (per-location replacement).

### Q2. Fidelity acceptance bar
The docx path already tolerates PhpWord roundtrip quirks (with two workarounds). What is the
acceptance bar for ODT — tables structurally intact + headers/footers preserved, with
paragraph/style drift acceptable? Need 3–5 representative government ODT fixtures to measure.

### Q3. Interim guard regardless of v1 timing
Should we land a **fail-loud guard** immediately (reject `.odt` in `preserve` mode, or force
PDF conversion) so the silent-leak bug is closed *before* full support ships? Recommended
yes — it's a one-branch change and removes a live privacy hole decoupled from the feature
work.

### Q4. Method naming
Rename `replaceWordsInWordDocument` → `replaceWordsInOfficeDocument`, or keep + document?
Cosmetic; affects one private method and its callers.

### Q5. ODT preview in the file viewer
Out of scope for anonymisation, but do we want `fileViewerService.js` to preview `.odt`
(would need an ODT→text/HTML path, not mammoth)? Track separately if desired.

## Risks and mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| PhpWord ODText writer drops/garbles styling on roundtrip | Medium | Validation gate checks PII removal, not fidelity; measure fidelity on real fixtures during the change; document known losses; PDF output remains the high-fidelity default |
| Word2007-only workarounds wrongly applied to ODText writer | Medium | Gate them behind the writer selection; unit-test both writer paths |
| Entity text split across `text:span` runs not caught by object-model setText | Low-Med | Same limitation the docx path already has; residual list + operator warning (best-effort policy already implemented); B is the future fix |
| Frontend enabled before backend fix → more users hit the bug | Medium | Land backend fix (or Q3 guard) first; sequence the two PRs |

## Recommended next step

1. Decide Q1 (A vs B) and Q3 (interim guard) with the team.
2. Scaffold the **OpenRegister** change (`odt-anonymisation-writeback`) — Approach A +
   validation gate + tests. Optionally split the Q3 guard as a tiny first PR.
3. Scaffold the paired **DocuDesk** change (`odt-anonymisation-frontend`) — widget
   allowlist + copy + i18n + test. Sequence after (or with) the backend fix.

## Appendix: References

- `lib/Service/File/DocumentProcessingHandler.php` — `replaceWords` (234), Word path
  (1109), Word2007 writer (1239), Numbering workaround (1226–1235), soft-break workaround
  (~1300+).
- `lib/Service/TextExtractionService.php` — `extractWord` (1452), `resolveWordReader`
  (1685), `isWordDocument` (2252).
- DocuDesk: `src/views/anonymization/AnonymizationWidget.vue` (upload gate),
  `lib/Service/Conversion/PhpWordBackend.php` (`READER_BY_EXT`).
- Sister OpenSpec changes:
  - `text-extraction-word-completeness` (done — closed the ODT READ/detection side).
  - `pdf-anonymisation-discovery` / `pdf-anonymisation` (the analogous writeback problem
    for PDF; source of the validation-gate pattern).
  - `anonymise-output-as-pdf-by-default`, `anonymise-pdf-only-output-mode` (DocuDesk output
    modes: `pdf-only` / `pdf` / `preserve`).
- PhpWord (`phpoffice/phpword ^1.2`, already vendored): `ODText` reader **and** writer both
  present (`vendor/phpoffice/phpword/src/PhpWord/Writer/ODText.php`).