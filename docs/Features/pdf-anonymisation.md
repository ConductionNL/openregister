# PDF Anonymisation

OpenRegister anonymises PDF inputs through the same `POST /api/files/{fileId}/anonymize` endpoint that handles DOCX / ODT / plain-text files. Previously the PDF branch fell through to a binary `str_ireplace` path that corrupted the output container; v1 of the `pdf-anonymisation` capability replaces that branch with a SAPP-driven byte-level replacement pipeline (Path A from the discovery doc) plus a smalot/pdfparser validation gate.

This document describes the runtime behaviour. The architectural rationale (eight-approach evaluation matrix, hard constraints, SAPP fork strategy) lives in `openspec/changes/pdf-anonymisation/{proposal,design}.md` and `openspec/changes/pdf-anonymisation-discovery/discovery.md`.

## Endpoint contract

No new HTTP endpoints. PDF inputs route through the existing anonymise endpoint unchanged:

```
POST /api/files/{fileId}/anonymize
```

### Success response (HTTP 200)

```json
{
    "success":            true,
    "message":            "File anonymized successfully",
    "original_file_id":   123,
    "anonymized_file_id": 456,
    "anonymized_path":    "/files/<user>/Documents/Letter_anonymized.pdf",
    "entities_replaced":  34
}
```

### Failure response (PDF-specific, structured)

For PDF inputs the controller maps `PdfAnonymisationException` reasons to HTTP statuses with a structured body:

| Reason                  | HTTP | Meaning                                                          |
|-------------------------|------|------------------------------------------------------------------|
| `encrypted_pdf`         | 422  | PDF is password-protected; out of scope for v1.                  |
| `text_layer_missing`    | 422  | Image-only scan; defer to the OCR capability before retrying.    |
| `validation_failed`     | 500  | Pipeline ran but the output still contains entity text (strict). |
| `internal_error`        | 500  | Unexpected SAPP / parser failure.                                |

```json
{
    "success": false,
    "error":   "pdf_anonymisation_failed",
    "reason":  "validation_failed",
    "details": {
        "stage":                       "validate.assert",
        "residual_count":              1,
        "streams_scanned":             42,
        "streams_modified":            18,
        "tj_arrays_modified":          7,
        "subset_font_fallbacks_used":  3,
        "font_encoding_misses":        0,
        "cid_split_mismatch":          0,
        "encoding_dict_unhandled":     0,
        "contents_array_pages":        0,
        "rejected_substitutions":      0
    }
}
```

Per ADR-005 the `details` surface is PII-free by construction — counts and structural counters only. The operator-supplied entity text never appears in the response body or in error logs.

## Hard constraints (locked from the discovery doc)

The implementation MUST satisfy all five — alternative approaches (visual overlay, render-from-text, commercial libraries, LibreOffice CLI sidecar, flatten-and-OCR) were ruled out because no combination satisfies all five together.

1. **No original PII in the output.** Every layer of the output PDF — visible text streams (Tj / TJ), hidden text, `/Info` dictionary, `/Metadata` XMP stream, outlines, annotations — MUST be free of the entity values from the substitution map.
2. **Identifiable placeholders.** Replacements take the form `[<TYPE>: <id>]` (the `entity-relation-grondslagen` convention). Pure black-bar redaction is ruled out — the placeholder cross-references the grondslagen summary.
3. **Layout preservation.** Tables stay structurally recognisable. Overflow within a cell, paragraph wrapping changes at the operator level, and minor visual shifts within a cell are acceptable; loss of table structure is not.
4. **FOSS only.** No commercial dependencies.
5. **No sidecars.** PHP-side processing only.

## Pipeline shape

```
PDF input
   │
   │ Encryption probe (trailer /Encrypt ref OR smalot "secured" message)
   │   └── → REASON_ENCRYPTED_PDF (HTTP 422)
   │
   │ Text-layer probe via smalot/pdfparser
   │   └── no extractable text → REASON_TEXT_LAYER_MISSING (HTTP 422,
   │                                                       defer to OCR)
   │
   │ SAPP loads via PDFDoc::from_string()
   │
   │ replace_text_in_document(substitutions: longest-first map):
   │   for each content-stream object,
   │     decode FlateDecode (+ LZW / ASCII85 / ASCIIHex / RunLength as
   │       upstream PRs land on the SAPP fork),
   │     resolve the active font's encoding (WinAnsi / MacRoman / Standard
   │       / Differences / Identity-H/V via ToUnicode CMap),
   │     flatten TJ kerning arrays into single-Tj strings,
   │     match per-font byte sequences against the substitution map,
   │     on match emit  /F-Replacement <size> Tf (<placeholder>) Tj
   │                    /F<original> <size> Tf
   │       so the placeholder renders via Helvetica (PDF base font) even
   │       when the original font is a subset.
   │
   │ PdfMetadataSanitizer.sanitize(doc):
   │   /Info → strip Title / Author / Subject / Keywords / Creator
   │            (Producer / CreationDate / ModDate preserved).
   │   /Metadata XMP → sentinel-replace dc:* / xmp:* / pdf:* element
   │                   bodies; preserve custom namespaces.
   │
   │ SAPP serialises with rebuild=true (incremental mode produces an
   │   unopenable PDF once _pdf_objects is populated by the replace API).
   │
   │ VALIDATION GATE (PdfTextReplacer::validateOutput)
   │   re-extract via smalot/pdfparser,
   │   whitespace-normalise both haystack and each needle,
   │   case-SENSITIVE mb_strpos for every substitution-map key,
   │   on any residual:
   │     emit PII-redacted warning (counts + structural counters only),
   │     strict=true  → fail closed (REASON_VALIDATION_FAILED, HTTP 500),
   │     strict=false → return the partial output (docx parity, lenient
   │                    behaviour for ad-hoc replaceWords callers).
```

## Validation-gate semantics

The validation gate is the single most important safety mechanism: it catches every silent-failure mode of byte-replace (encoding mismatches, missed splits, font edge cases) by detecting residual entity text in the output.

Mode is selected by the caller (`PdfTextReplacer::validateOutput(..., bool $strict)`):

- **Strict (`$strict = true`)** — the entity-anonymisation flow `anonymizeDocument` → `replaceWords(strict: true)`. A GDPR-anonymised file marked `_anonymized` MUST NOT be written while it still contains the original entity text. Fail-closed.
- **Lenient (`$strict = false`)** — ad-hoc `replaceWords`. Preserves parity with the DOCX path (PHPWord + `str_ireplace` returns a partial result silently when a needle is split across `<w:r>` runs). Warning is logged structurally; the partial output is returned.

Per the design's D6-amendment the REQ:no-residual-PII Requirement is mode-conditional: GDPR-critical anonymisation retains fail-closed semantics, ad-hoc replace stays lenient until DOCX receives a matching gate. Otherwise the asymmetry returns for ad-hoc callers.

The validation gate uses **Choice α**: smalot re-extract + substring search. Re-extracted text matches what an automated downstream consumer would see. Choice β (full Presidio / openanonymiser round-trip) is reserved for a follow-up if α's blind spots surface in practice.

## Encrypted-PDF rejection

Encrypted PDFs raise `PdfAnonymisationException(reason: 'encrypted_pdf')` → HTTP 422. Detection runs twice:

1. **Trailer probe.** A `/Encrypt N G R` indirect reference in the trailer dictionary anchors a regex that avoids false positives from the literal string appearing inside a content stream.
2. **smalot probe.** When the trailer probe missed but smalot rejects the input with a `secured` / `encrypt` message, the same `REASON_ENCRYPTED_PDF` is raised.

Anonymising a password-protected PDF requires the password; v1 does not accept passwords.

## Image-only PDF → defer to OCR

PDFs whose text layer extracts to an empty string after `trim()` raise `PdfAnonymisationException(reason: 'text_layer_missing')`. The caller is expected to route to the `ocr-document-scanning` capability before retrying the anonymise call. v1 does NOT auto-redirect — the controller surfaces a structured 422 so the caller can choose its own dispatch.

## Metadata sanitisation

Strips PII-bearing fields in parity with the sister `office-document-sanitization` change:

- `/Info` fields `/Title`, `/Author`, `/Subject`, `/Keywords`, `/Creator` → sentinel `[REDACTED]`.
- `/Info` fields `/Producer`, `/CreationDate`, `/ModDate` → preserved (provenance, no PII).
- `/Metadata` XMP stream → element bodies for `dc:*`, `xmp:*`, `pdf:*` namespaces sentinel-replaced; custom namespaces (Conduction-internal workflow metadata, government-template tags) preserved.

## Operator escalation flow on validation failure

When the strict-mode gate fails:

1. The output PDF is **discarded** — never persisted, never returned. The original file is untouched.
2. A PII-redacted warning log line is emitted with the structural counters (`residual_count`, `streams_scanned`, `streams_modified`, `tj_arrays_modified`, `subset_font_fallbacks_used`, `font_encoding_misses`, `cid_split_mismatch`, `encoding_dict_unhandled`, `contents_array_pages`, `rejected_substitutions`).
3. The controller returns HTTP 500 with `{ "error": "pdf_anonymisation_failed", "reason": "validation_failed", "details": { ... PII-free counters ... } }`.
4. Operator escalates: the diagnostic counters tell ops which SAPP stage missed (font encoding, CID split, unhandled encoding dict, contents array page count, rejected substitution count). The follow-up `pdf-anonymisation-odt-fallback` (Path B) will slot in here once Path A's fall-through rate is measurable from real Woo PDFs.

## Cross-app coordination

- **DocuDesk `anonymise-output-as-pdf-by-default`.** PDF outputs from DocuDesk's anonymise flow are no longer wrapped around a corrupted byte-replace result. No DocuDesk-side change required.
- **`ocr-document-scanning`.** Image-only PDFs defer to the OCR capability — see the failure-mode table above.
- **Sister changes `office-document-sanitization` and `text-extraction-office-completeness`.** Orthogonal — they cover DOCX / ODT branches.

## Composer dependency

The PDF byte-replace pipeline uses the `ddn/sapp` fork hosted at `https://github.com/ConductionNL/sapp`. While upstream PRs land, OpenRegister pins to a specific work-branch commit SHA via the VCS repository entry in `composer.json` for build reproducibility. When upstream `dealfonso/sapp` merges the work and tags a release, the `repositories` entry is removed and the constraint becomes a normal version range.

The upstream-PR series tracking the fork→upstream transition lives at [`ConductionNL/sapp:docs/upstream-prs/`](https://github.com/ConductionNL/sapp/tree/work/text-replacement/docs/upstream-prs).

## Spec references

- `openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md` — canonical Requirement set.
- `openspec/changes/pdf-anonymisation/proposal.md` — what changes.
- `openspec/changes/pdf-anonymisation/design.md` — decisions (PoC scope, validation gate semantics, failure handling, XMP handling, upstream contribution policy).
- `openspec/changes/pdf-anonymisation-discovery/discovery.md` — eight-approach evaluation matrix.
- `openspec/changes/pdf-anonymisation-odt-fallback/` (follow-up, scaffolded) — Path B (NC Office ODT round-trip) reserved for once Path A's fall-through rate is measured.
