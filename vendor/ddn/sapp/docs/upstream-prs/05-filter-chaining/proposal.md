# Proposal — Filter chaining (`/Filter` array form)

## Why

PDF 1.7 §7.4.1 (Stream Decoding Parameters and Filter Pipelines) allows `/Filter` to be either a single name (e.g. `/FlateDecode`) OR an array of names (e.g. `[/ASCII85Decode /FlateDecode]`). When it's an array, the filters apply in order — ASCII85 first, FlateDecode on the result.

SAPP today handles ONLY the single-name form. The dispatch in `get_stream()` is `switch ($this->_value['Filter'])` against bare strings; the array form falls through to the existing "unknown compression method" error.

Real-world PDFs use the array form. The most common pairing is `/ASCII85Decode /FlateDecode` — ASCII85 wraps the FlateDecoded data in 7-bit-safe text. Without chaining, every such PDF is opaque to SAPP.

This is the **dispatch refactor** that lets the four individual decoder PRs (01–04) actually work together. It's the keystone of the filter-coverage half of the text-replacement series.

## What Changes

- **REFACTOR** the dispatch in `PDFObject::get_stream()` from a switch-on-name into a small pipeline:
  - Normalise `$this->_value['Filter']` into an ordered list of filter names (single name → 1-element list; array of names → list as-is).
  - Normalise `$this->_value['DecodeParms']` into a parallel list of parameter dicts (per the spec's parallel-array convention).
  - Apply each filter in order via a per-filter `decodeOne()` dispatch.
- **REFACTOR** the dispatch in `PDFObject::set_stream()` symmetrically: apply filters in REVERSE order to invert the decoding pipeline.
- **NEW helper:** `protected function normaliseFilterNames($filter): ?array` — returns the ordered list or null on unrecognised shape.
- **NEW helper:** `protected function normaliseDecodeParms($params, int $expected_count): array` — returns the parallel param list, padded with empty arrays where needed.
- **NEW helper:** `protected function decodeOne(string $filter_name, string $data, array $params): string|false` — the per-filter dispatch that the chain calls into.
- **NO behaviour change** for the single-name form: `/Filter /FlateDecode` continues to produce byte-identical output before and after the refactor.

## Impact

- **Spec target:** PDF 1.7 §7.4.1 (Stream Decoding Parameters and Filter Pipelines).
- **Unlocks:** every PDF using chained filters. Most commonly ASCII85+Flate. Sometimes ASCIIHex+Flate.
- **Refactor risk:** moderate. The dispatch changes shape, but the public API of `get_stream` / `set_stream` is preserved. The byte-equivalence guarantee for single-name inputs is REQ-01 and is checked against the existing `examples/testdoc.pdf` fixture.
- **Out of scope:**
  - `/F`, `/FFilter`, `/FDecodeParms` (filter pipelines on external file streams — separate concern).
  - Encrypted-stream handling (`/Filter /Crypt` — separate concern).
  - Image-only filters (`/DCTDecode`, `/CCITTFaxDecode`, `/JBIG2Decode`, `/JPXDecode` — not relevant to text-replacement).


---

---

## Implementation note

See `openspec/changes/feat-filter-chain-dispatch/design.md` (the canonical artefact) for the shipped-implementation note, decisions D1-D6, and the method-name listing. This file intentionally keeps the original proposal/design/tasks content for upstream submission to dealfonso/sapp; the implementation specifics are tracked in the OpenSpec change to avoid duplicate-source drift across four files.
