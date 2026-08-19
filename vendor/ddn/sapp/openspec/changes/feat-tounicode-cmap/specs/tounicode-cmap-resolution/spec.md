**Status**: planned
**Scope**: change `feat-tounicode-cmap` (delta spec)
**OpenSpec changes**:
- `feat-tounicode-cmap` (in-progress)

## Purpose

Capability contract for `tounicode-cmap-resolution` — the normative SHALL/MUST
behaviour the change must deliver. Scenarios below are the
acceptance criteria; tasks under the change's `tasks.md` reference
these requirements by REQ-NNN id.

## Non-Functional Requirements

- PHP >= 7.4 compatibility per upstream sapp's composer constraint.
- Zero new composer dependencies.
- snake_case method names on new utility helpers; PascalCase on
  filter names (matches the existing `FlateDecode` convention).
- All round-trip scenarios MUST be lossless byte-for-byte unless
  the spec explicitly documents a deviation.

## Acceptance Criteria

- Every requirement below MUST be exercised by at least one scenario
  in the change's verification gate under `examples/`.
- The change's `tasks.md` MUST cite each REQ-NNN it implements.
- Existing verification gates (PoC and prior changes) MUST remain
  green after the change lands.

## Notes

This is a delta spec — the canonical spec will be at
`openspec/specs/tounicode-cmap-resolution/spec.md` after `/opsx-archive`. The
delta operations below (`## ADDED Requirements`, `## MODIFIED
Requirements`) are merged into the canonical spec by the archiver.

## ADDED Requirements

### REQ-001: CMap parser SHALL handle Word-emitted ToUnicode CMap shape

The parser MUST recognise `beginbfchar`/`endbfchar` blocks and `beginbfrange`/`endbfrange` blocks per Adobe Tech Note 5411. It MUST produce a forward map (Unicode → CID-byte-sequence) and a reverse map (CID-byte-sequence → Unicode) from the parsed entries.

#### Scenario: bfchar block

- WHEN the CMap stream contains `2 beginbfchar <0041> <0041> <0042> <0042> endbfchar` (CID 0x41 → U+0041 'A', CID 0x42 → U+0042 'B')
- THEN the reverse map MUST return `"A"` for input `"\x00\x41"` and `"B"` for input `"\x00\x42"`
- AND the forward map MUST return `"\x00\x41"` for input `"A"` and `"\x00\x42"` for input `"B"`

#### Scenario: bfrange block with starting Unicode

- WHEN the CMap stream contains `1 beginbfrange <0041> <005A> <0041> endbfrange` (CIDs 0x41..0x5A → U+0041..U+005A, the ASCII uppercase range)
- THEN the reverse map MUST return `"M"` for input `"\x00\x4D"`
- AND the forward map MUST return `"\x00\x4D"` for input `"M"`

#### Scenario: bfrange block with explicit Unicode array

- WHEN the CMap stream contains `1 beginbfrange <0001> <0003> [<0041> <0042> <0043>] endbfrange` (CIDs 1..3 → "A", "B", "C")
- THEN the reverse map MUST return `"B"` for input `"\x00\x02"`

#### Scenario: Multi-codepoint Unicode target

- WHEN the CMap stream contains `1 beginbfchar <0050> <00660069> endbfchar` (CID 0x50 → "fi" ligature, encoded as U+0066 U+0069)
- THEN the reverse map MUST return the NFC-normalised string `"fi"` for input `"\x00\x50"`

### REQ-002: CMap parser SHALL fail safely on unsupported syntax

Inputs containing unsupported constructs (nested CMap references via `usecmap`, `begincidrange` for CIDFont selection, etc.) MUST cause `p_error()` and the parser MUST return a null/empty CMap. Callers MUST be able to detect the failure mode without exception handling.

#### Scenario: Unsupported usecmap directive

- WHEN the CMap stream contains `/Identity usecmap`
- THEN the parser MUST call `p_error()` identifying the unsupported directive
- AND the resulting CMap MUST treat all inputs as unencodable

### REQ-003: Implicit font encodings SHALL be honoured for simple fonts

When a font has no `/ToUnicode` CMap, `FontEncoding::forName($encodingName)` MUST return a working encoding for `/WinAnsiEncoding`, `/MacRomanEncoding`, `/StandardEncoding`, `/Identity-H`, `/Identity-V`. Unrecognised names MUST return a null/empty encoding (callers diagnose).

#### Scenario: WinAnsiEncoding byte-to-unicode

- WHEN the encoding is `/WinAnsiEncoding` and the input byte is 0x41
- THEN `byteToUnicode(0x41)` MUST return `"A"`

#### Scenario: WinAnsiEncoding round-trip on common characters

- WHEN the encoding is `/WinAnsiEncoding` and the input character is any printable ASCII character (0x20..0x7E)
- THEN `unicodeToByte(byteToUnicode($b))` MUST equal `$b` for every byte in that range

#### Scenario: Identity-H passthrough

- WHEN the encoding is `/Identity-H` and the input byte sequence is `"\x00\x41"` (2-byte CID 0x41)
- THEN `byteToUnicode` is undefined (Identity-H requires a ToUnicode CMap)
- AND `FontEncoding::isIdentityH()` MUST return `true`

### REQ-004: Font resolution SHALL walk the page-tree resource inheritance

`PDFDoc::resolveFontMap(int $pageOid, string $resourceName)` MUST return the font's forward + reverse maps for the named font resource. It MUST search the page's `/Resources`, then the page's parent pages' `/Resources`, then the document catalog's `/Resources` (PDF 1.7 §7.5.4 inheritance).

#### Scenario: Font on the page itself

- WHEN the page object's `/Resources/Font/F1` references a font with a `/ToUnicode` CMap
- THEN `resolveFontMap($pageOid, 'F1')` MUST return a non-null result with `forward` + `reverse` callables and the font's `/BaseFont` value as `name`

#### Scenario: Font inherited from a parent page node

- WHEN a page does NOT have `/Resources` but its parent does, and the parent's `/Resources/Font/F1` is a valid font
- THEN `resolveFontMap($pageOid, 'F1')` MUST resolve via inheritance and return the font

#### Scenario: Unknown font resource name

- WHEN the page has no `/Resources/Font/F99` (and no ancestor has it either)
- THEN `resolveFontMap($pageOid, 'F99')` MUST return `null`

#### Scenario: Parsed CMaps are cached

- WHEN `resolveFontMap` is called twice with the same arguments
- THEN the second call MUST NOT re-parse the CMap stream (verifiable via spy on the parser's invocation count)
