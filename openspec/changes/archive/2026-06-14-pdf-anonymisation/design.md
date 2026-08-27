# Design — pdf-anonymisation

## Context

See `openspec/changes/pdf-anonymisation-discovery/discovery.md` for the eight-approach evaluation matrix and the FOSS-only / no-sidecar / no-PII-leak constraints. This document captures the implementation design decisions for the chosen approach (Path A — SAPP byte-replace).

## Decisions

### D1. SAPP fork strategy

We work from `Conduction/sapp` (a GitHub fork of `dealfonso/sapp`). A long-lived `work/text-replacement` integration branch is what OpenRegister consumes during development. Each upstream-bound feature lives on its own branch off upstream `main` so the diffs stay clean and rebasable; those feature branches merge into `work/text-replacement` for downstream integration testing, and become the PRs we eventually file upstream.

PoC-first sequencing: build a working end-to-end thing on `work/text-replacement` BEFORE we post upstream issues / PRs. That way we contribute working code, not promises.

Branch layout on the fork:
- `main` — tracks `upstream/main`. Never modified directly.
- `work/text-replacement` — long-lived integration branch. OpenRegister `composer.json` points here.
- `feat/<feature-name>` — one branch per upstream-bound feature. Off `main`. Squashed into `work/text-replacement` as features mature.

Issue / PR drafts for each upstream-bound feature live in the fork itself under [`docs/upstream-prs/`](https://github.com/ConductionNL/sapp/tree/work/text-replacement/docs/upstream-prs). They're written in "we have this working" framing, posted only after the corresponding code is on `work/text-replacement` and a fixture demonstrates it works. The fork's `README.conduction.md` carries the PR-series index + workflow.

### D2. Composer wiring

`OpenRegister/composer.json` gains a `repositories` entry pointing at the fork, and `ddn/sapp` constraint becomes `dev-work/text-replacement as 1.x-dev` until upstream merges:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/ConductionNL/sapp"
    }
],
"require": {
    "ddn/sapp": "dev-work/text-replacement as 1.x-dev",
    ...
}
```

When upstream merges the work (release tagged `1.x`), the `repositories` entry is removed and the constraint becomes a normal version range. The diff at that point is two lines.

> **Current pin (2026-06):** `composer.json`/`composer.lock` pin `ddn/sapp` to the bug-fix branch `dev-fix/rebuild-strip-prev-classic-xref#b1c411d` (a commit-SHA pin for reproducibility), not the long-lived `work/text-replacement` integration branch named above. This branch carries the `rebuild`/`strip-prev`/`classic-xref` fixes the byte-replace pipeline depends on. **Tracking:** it must fold back into `work/text-replacement` (and eventually upstream `dealfonso/sapp`) before the pin can be relaxed — see <https://github.com/ConductionNL/sapp/branches>. Until then the SHA pin is the source of truth.

### D3. Path A pipeline

```
PDF input
   │
   │ smalot/pdfparser probes for text layer
   │   ├── no text layer? → defer to ocr-document-scanning
   │   └── has text layer? → continue
   │
   │ SAPP loads via ddn\sapp\PDFDoc::from_string()
   │
   │ For each content-stream object:
   │   1. Decode filter chain (FlateDecode + LZW + ASCII85 + ASCIIHex + RunLength)
   │   2. Read the active font's encoding (WinAnsi / MacRoman / Standard / Differences / Identity-H/V via ToUnicode CMap)
   │   3. Pre-pass: flatten TJ kerning arrays to single Tj strings
   │   4. Match per-font byte sequences against the substitution map (longest-first)
   │   5. On match: emit `/F-Replacement 11 Tf (<placeholder>) Tj /F<original> 11 Tf` (font switch + Helvetica replacement)
   │   6. Post-pass: collapse adjacent-duplicate placeholders
   │
   │ Strip PDF metadata (/Info + /XMP, parity with office-document-sanitization)
   │
   │ SAPP rewrites the PDF with corrected xref tables (to_pdf_file_s(true))
   │
   │ VALIDATION GATE
   │   Re-extract output via smalot/pdfparser
   │   For each entity in the substitution map (including variants), assert
   │   the original text is absent
   │
   │   ├── PASS → return output PDF
   │   └── FAIL → discard output, raise PdfAnonymisationException
   │              (Path B fall-through deferred to follow-up change)
```

### D4. PoC scope (locks the first end-to-end milestone)

The PoC succeeds when ONE specific real-world Woo PDF (synthesised, no actual PII) round-trips through the pipeline with:

- One filter (FlateDecode — the >95% case)
- One font encoding (WinAnsiEncoding — the Word-default case)
- One substitution (`"Jan Jansen"` → `"[PERSON: 7]"`)
- One Tj operator carrying the match (no TJ kerning splits required)
- Validation gate confirms the output has no residual `"Jan Jansen"`

Everything else (LZW / ASCII85 / ASCIIHex / RunLength / Identity-H CMaps / TJ flattening / adjacent-placeholder collapse / metadata sanitisation) is deferred to post-PoC iterations. The PoC's job is to prove the SAPP-based pipeline can produce a clean PDF on the simplest possible input; the iterations expand encoding / filter coverage and ruggedise the validation gate.

PoC artefacts that come out of this milestone:

1. A working `replaceTextInDocument()` PoC method on the SAPP fork (probably reads more like a thin wrapper around object iteration + stream rewrite).
2. A small OpenRegister integration test that loads a fixture, runs replacement, asserts output is clean.
3. Empirical answers to discovery Q5 / Q6 (what filters appear in practice, what encodings dominate) — informs the iteration order after the PoC.

After PoC, upstream issues for filters / CMap parser / text-replacement API get posted (NOT before).

### D5. Validation gate semantics

The validation gate is the single most important safety mechanism: it catches every silent-failure mode of byte-replace (encoding mismatches, missed splits, font edge cases) by detecting residual entity text in the output. Two implementation choices:

**Choice α — smalot re-extract + substring search** (recommended): re-extract the output PDF's text via `smalot/pdfparser`, run each substitution-map key against the extracted text via `mb_stripos`. PASS when every key is absent. Trade-off: smalot's extraction is fast (~100ms for typical Woo PDF) but misses text rendered in unusual ways (positioned text fragments that happen to spell a word). For the threat model — "an automated downstream consumer reads the file" — this matches what such a consumer would see.

**Choice β — round-trip through entity detection** (heavyweight): re-run Presidio / openanonymiser on the output and assert no entities are detected. Safer but ~2-5s per file and requires the detection backend to be configured. Reserved for a follow-up if α turns out to have blind spots in practice.

v1 uses α. Document the limitation in the spec ("residual text rendered via unusual operator sequences is out of scope; covered by α's known coverage of smalot extraction").

### D6. Failure handling — v1 fails closed, no Path B yet

**[Superseded 2026-05-29 — see D6-amendment below.]** When the validation gate fails on a v1 output, the original v1 behaviour was:

1. Discard the output (do NOT persist or return it).
2. Log the diagnostic surface: which entities are still in the output, which content streams produced unmatched text, what fonts were in scope.
3. Raise `PdfAnonymisationException` with `reason = 'validation_failed'`. The controller maps to HTTP 500 with `{ "error": "pdf_anonymisation_failed", "reason": "validation_failed" }`. Operator sees the failure and knows to escalate.

Path B (NC Office ODT fallback) — when added in a follow-up — slots in BETWEEN steps 1 and 3 as the next try. v1 explicitly leaves the operator with a clear failure rather than a misleadingly "successful" output.

### D6-amendment. Lenient gate for docx parity (2026-05-29)

Field-testing on the Notulen20190602 fixture surfaced that the v1 fail-closed policy makes PDF strictly less usable than the existing docx path: docx anonymisation uses PHPWord + `str_ireplace` with NO validation gate, so it returns a partial result silently when a needle is split across `<w:r>` runs. Failing closed on PDF while docx silently leaks meant users got contradictory feedback for the same logical content.

The validation gate behaviour is **mode-dependent** (`PdfTextReplacer::validateOutput(..., bool $strict)`):
1. Re-extract via `smalot/pdfparser` (unchanged).
2. If any substitution-map key remains, emit a PII-redacted `warning` log line with the structural diagnostic (residual_count + replaceStats counters). NEVER include the entity text in the log per ADR-005.
3. Then branch on the caller's intent:
   - **Lenient (`$strict = false`, the default — ad-hoc `replaceWords`)**: return the partial output; the file IS written. This preserves parity with the docx path, which has no validation gate (PHPWord + `str_ireplace` returns a partial result silently when a needle is split across `<w:r>` runs).
   - **Strict (`$strict = true`, the entity-anonymisation flow `anonymizeDocument` → `replaceWords(strict: true)`)**: fail CLOSED — throw `PdfAnonymisationException(REASON_VALIDATION_FAILED)` (controller → HTTP 500). A GDPR-anonymised file marked `_anonymized` must never be written while it still contains the original entity text.

REQ:no-residual-PII is therefore **conditional on mode**: the GDPR-critical entity-anonymisation path retains fail-closed semantics, while ad-hoc replacement is lenient. Removing the lenient default entirely is still gated on docx receiving a matching validation gate first (otherwise the asymmetry returns for ad-hoc replace).

### D7. Placeholder format

Discovery Q2 asked whether Helvetica fallback guarantees rendering of the existing `[<TYPE>: <id>]` format. Decision: yes — Helvetica is one of the 14 PDF standard base fonts every reader must render natively without embedding. ASCII brackets / colons / digits / spaces are all in WinAnsiEncoding's 0x20–0x7F range. Keep the existing format.

If Helvetica fallback proves unreliable on some reader in practice (very unlikely), the fallback formats listed in discovery Q2 (`<TYPE>_<id>`, `<TYPE>-<id>`) remain available as later refinements.

### D8. XMP metadata handling

Discovery Q3 asked: parse + sentinel-replace, or remove entirely. Decision: parse + sentinel-replace, parity with office-document-sanitization's metadata rules. The XMP stream may carry workflow-relevant metadata in custom namespaces; nuking it would lose downstream observability. Standard dc:* / xmp:* / pdf:* fields get the sentinel; custom namespaces are preserved.

### D9. Cross-change dependency stance

This change DEPENDS on:

- Sister fork features on `Conduction/sapp` `work/text-replacement` (composer dependency).
- Existing `entity-relation-grondslagen` substitution-map convention (the `[<TYPE>: <id>]` format and the "all variants share the same placeholder id" invariant).

This change DOES NOT BLOCK:

- `office-document-sanitization` (sister change, ODT/DOCX track).
- `text-extraction-office-completeness` (sister change, walker depth + ODT first-class).
- `ocr-document-scanning` (existing change, image-only PDFs).
- `pdf-anonymisation-odt-fallback` (follow-up change, Path B).

Path B's follow-up change WILL depend on this one (specifically the validation-gate API) when it lands.

### D10. Upstream contribution policy

Anything that's a generic PDF-manipulation primitive goes upstream. Anything that's OpenRegister-specific stays here.

| Goes upstream (`Conduction/sapp` → `dealfonso/sapp`) | Stays in OpenRegister |
|---------------------------------------------------------|------------------------|
| LZWDecode / ASCII85Decode / ASCIIHexDecode / RunLengthDecode filter decoders | `[<TYPE>: <id>]` placeholder format convention |
| ToUnicode CMap parser, per-font encoding resolver | Substitution-map shape (variants-with-shared-id rule) |
| TJ-kerning-array flattening pre-pass | Wiring into `DocumentProcessingHandler::anonymizeDocument` |
| `replaceTextInDocument(array $substitutions, array $options)` flagship API | Validation gate (smalot re-extract) |
| Helvetica base-font-fallback helper | Adjacent-duplicate placeholder collapse |
|  | PDF metadata sanitisation rules tied to our anonymisation contract |

The split forces us to design the SAPP-side API generically — substitutions are operator-supplied strings, options carry the font-switch / fallback-font policy. The OpenRegister side calls it with our specific substitution map. Cleanest dependency story.

## Risks and mitigations

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| SAPP maintainer rejects scope or has a bus factor of 1 | Medium | Build PoC in fork first; if upstream stalls, we ship from the fork (worst case our fork becomes the new canonical home for these features — LGPL permits this) |
| Real Woo PDFs use filter / encoding combinations we haven't tested | Medium | Empirical spike on real fixtures during the PoC (discovery Q5); ruggedise the iteration backlog based on what shows up |
| Validation gate produces false positives ("text in stream but is decorative not actual") | Low | Document in spec; manual review path; consider Choice β fall-back if real cases surface |
| Helvetica fallback renders unexpectedly in some PDF reader | Low | Helvetica is a PDF base font; test against Adobe Acrobat / evince / Chromium / Firefox |
| Composer wiring breaks if `Conduction/sapp` becomes unreachable | Low | Mirror the fork; pin to a specific commit SHA rather than the branch tip for production builds |
| ddn/dealfonso reluctant to accept the larger PRs (CMap parser, text-replacement API) | Medium-High | Build maintainer trust with small PRs first (the four filter decoders + ToUnicode parser); the flagship API arrives last with the prior PRs as social proof |

## Open questions (resolved during PoC)

These were not decidable at the design-doc stage; the PoC will surface them.

- **OQ1.** What's the actual rate of FlateDecode-only vs. multi-filter chains in real Woo PDFs? (Affects priority of the chaining refactor.)
- **OQ2.** What's the actual rate of Identity-H composite fonts in Word-generated Woo PDFs? (Affects priority of the ToUnicode parser PR.)
- **OQ3.** What percent of entities split across multiple Tj operators in practice? (Determines whether variant-driven splits + adjacent-placeholder-collapse covers the field, or if we need smarter cross-Tj matching.)
- **OQ4.** Does the validation gate catch every failure mode in practice, or do we need Choice β fall-back?

PoC measurements update this doc.

## References

- Discovery doc: `openspec/changes/pdf-anonymisation-discovery/discovery.md`
- Upstream issue drafts: `upstream-issues/` (this directory)
- SAPP fork: `https://github.com/ConductionNL/sapp` (branch `work/text-replacement`)
- SAPP upstream: `https://github.com/dealfonso/sapp` (LGPL-3.0-or-later)
- PDF 1.7 reference: §3.3 (filters), §5.3 (text-show operators), §5.5 (font encodings), §7.4.2 (ASCIIHexDecode), §7.4.3 (ASCII85Decode), §7.4.4 (LZWDecode), §7.4.5 (FlateDecode), §7.4.6 (RunLengthDecode)
