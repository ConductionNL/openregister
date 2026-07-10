---
status: in-progress
---

# ODT Anonymisation

## Purpose
Defines how OpenRegister redacts `.odt` (OpenDocument Text) inputs in-place through `DocumentProcessingHandler`. `.odt` files are ZIP containers whose text lives (normally deflated) inside `content.xml`, so the legacy raw-byte `str_ireplace` fallback either returns a byte-identical (un-redacted) file — a silent PII leak reported as success — or corrupts the ZIP. This capability routes `.odt` into the shared PhpWord object-model roundtrip (reusing the DOCX traversal verbatim), selects the `ODText` writer by input extension, gates the two Word2007-writer-specific roundtrip workarounds off the ODT path, and adds a post-write validation gate that re-extracts the output via the (already ODT-aware) `TextExtractionService::extractWord()` and fails loud — recording residuals via the existing best-effort policy — so an ODT anonymisation never emits a byte-identical or corrupt file reported as successfully anonymised.

**OpenSpec changes**
- `odt-anonymisation-writeback` (active) — routes `.odt` into `replaceWordsInWordDocument()`, adds writer selection by input extension (`ODText` for `.odt`), gates the `Style\Numbering` and soft-line-break `<w:br/>` workarounds behind the Word2007 writer, and adds the fail-loud re-extraction validation gate. Reuses `text-extraction-word-completeness` (archived) for the ODT-aware `extractWord()`.

## Requirements

### Requirement: ODT anonymisation behaviour is governed by the active change
While this capability is in-progress, normative requirements MUST be sourced from the active change `odt-anonymisation-writeback` under `openspec/changes/`. Implementers MUST treat this canonical spec as a placeholder until the change is archived and its delta is merged here.

#### Scenario: Implementer needs the canonical contract
- **WHEN** an implementer needs the normative behaviour for `.odt` anonymisation writeback
- **THEN** they MUST consult the active change `odt-anonymisation-writeback`
