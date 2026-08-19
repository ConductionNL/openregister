# Proposal — `/ASCIIHexDecode` filter support

## Why

`PDFObject::get_stream($raw = false)` dispatches by `$this->_value['Filter']`. The current `switch` statement handles `/FlateDecode` only; every other filter value falls through to `p_error('unknown compression method ...')` and the stream cannot be read or written. Real-world PDFs carry content streams encoded with other filters defined in PDF 1.7 §7.4 — `/LZWDecode`, `/ASCII85Decode`, `/ASCIIHexDecode`, `/RunLengthDecode` — and any document using one of those becomes opaque to SAPP-based tooling.

This feature is the first in a series adding decoder coverage for the text-relevant subset of the PDF filter set. The downstream consumer is text replacement in PDF content streams for GDPR anonymisation; without filter coverage, the consumer would silently lose data on any PDF outside the FlateDecode happy path.

`/ASCIIHexDecode` is the simplest of the four to add (~10 LOC), so it's the first PR. It also doubles as a low-stakes first contribution to feel out the maintainer's review preferences (code style, test idioms, scope expectations) before the larger PRs in the series ship.

## What Changes

- **NEW:** Static helper `PDFObject::ASCIIHexDecode($stream)` — strips whitespace, locates the `>` EOD marker, pads odd-length input per PDF 1.7 §7.4.2, decodes via `hex2bin`. Returns the decoded bytes or routes through `p_error` on invalid input.
- **NEW case** in `PDFObject::get_stream()` switch: `'/ASCIIHexDecode' => self::ASCIIHexDecode($this->_stream)`.
- **NEW case** in `PDFObject::set_stream()` switch: encode via `bin2hex` + `>` EOD marker for write-back round-trip parity.
- **NO new public types.** The helper is `protected static` like its `FlateDecode` sibling.
- **NO change** to the existing FlateDecode path.

## Impact

- **SAPP API surface:** unchanged at the public level. `get_stream(false)` and `set_stream(..., false)` now handle one more filter.
- **Downstream consumers:** PDF objects using `/Filter /ASCIIHexDecode` become readable / writable. Nothing previously working regresses.
- **Spec target:** PDF 1.7 §7.4.2 (ASCIIHexDecode Filter).
- **Out of scope:** filter chaining (`/Filter [/X /Y]` array form, separate PR), other filters in the series (separate PRs).


---

---

## Implementation note

See `openspec/changes/feat-asciihex-decode/design.md` (the canonical artefact) for the shipped-implementation note. This file keeps the original proposal/design/tasks content for the eventual upstream submission to dealfonso/sapp; implementation specifics are tracked in the OpenSpec change to avoid duplicate-source drift.
