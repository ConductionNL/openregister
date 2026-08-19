**Status**: planned
**Scope**: change `feat-tounicode-cmap` (delta spec)
**OpenSpec changes**:
- `feat-tounicode-cmap` (in-progress)

## Purpose

Capability contract for `text-replacement` — the normative SHALL/MUST
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
`openspec/specs/text-replacement/spec.md` after `/opsx-archive`. The
delta operations below (`## ADDED Requirements`, `## MODIFIED
Requirements`) are merged into the canonical spec by the archiver.

## ADDED Requirements

### REQ-001: Replacement SHALL match the needle in text space, not byte space

`PDFDoc::replaceTextInDocument(array $substitutions)` MUST resolve every text-showing operator's operand bytes through the font active at that operator's position, NFC-normalise both the resolved text and the needles, and search for matches in text space. A byte sequence whose Unicode resolution matches a needle MUST be replaced; a byte sequence that happens to contain the needle's literal bytes but resolves to different Unicode MUST NOT be replaced.

#### Scenario: Identity-H subset font with a real-world Tj operator

- WHEN the content stream contains `[(<0001000200030004>) Tj]` and the active font's `/ToUnicode` CMap maps CIDs 1..4 to `"J", "a", "n", " "` and 5..9 to "J", "a", "n", "s", "e", "n"
- AND the surrounding stream emits CIDs 5..9 as `<00050006000700080009>` immediately after
- AND the substitution is `{"Jan Jansen": "[PERSON: 7]"}`
- THEN `replaceTextInDocument` MUST replace the matched CID range with CID bytes encoding `[PERSON: 7]` via the active font's forward map

#### Scenario: Byte-coincidence MUST NOT trigger a match

- WHEN the content stream's raw bytes happen to contain the literal substring `"Jan Jansen"` but those bytes are CIDs that resolve to entirely different Unicode (e.g. "abcdefghij" in some custom encoding)
- AND the substitution is `{"Jan Jansen": "[PERSON: 7]"}`
- THEN `replaceTextInDocument` MUST NOT replace those bytes

#### Scenario: Whitespace + NFC normalisation

- WHEN the content stream emits `"Jan Jansen"` (non-breaking space U+00A0 between the words) and the substitution needle is `"Jan Jansen"` (regular space)
- THEN the match MUST NOT fire (NFC does not equate U+00A0 to U+0020). Operators wanting that match must supply the variant explicitly.

#### Scenario: Ligature flattening via multi-codepoint ToUnicode target

- WHEN the content stream emits a CID whose ToUnicode mapping is the "fi" ligature → `"fi"` and the surrounding text completes the word `"office"`
- AND the needle is `"office"`
- THEN the match MUST fire (the ligature CID is treated as the two-character sequence after NFC)

### REQ-002: Placeholder SHALL be emitted via the active font's forward map

The placeholder string for a successful match MUST be encoded through the font that was active at the START of the match. The resulting CID byte sequence MUST be wrapped in a `Tj` operator and spliced in place of the source operator(s) that contributed to the match. Intermediate `Tf` switches inside the match span MUST be elided.

#### Scenario: Single-font match

- WHEN the match span lies entirely within a single `Tf` scope and the active font's forward map can encode every character of the placeholder
- THEN the spliced content stream MUST contain a single `(<encoded CIDs>) Tj` operator at the match position

#### Scenario: Multi-font match uses the start font

- WHEN the match span crosses one or more `Tf` operators
- THEN the placeholder MUST be encoded via the font active at the START of the match
- AND the intermediate `Tf` operators inside the match span MUST be elided from the spliced stream
- AND `Tf` operators outside the match span MUST be preserved

### REQ-003: Unencodable placeholders SHALL be diagnosed, not corrupted

If the active font's forward map cannot encode every character of a placeholder, the substitution for that match MUST be skipped (source bytes left unchanged) and an entry MUST be added to the returned diagnostic surface under `font_encoding_misses[$oid][$needle] = $font_base_name`. The other substitutions in the same call MUST proceed normally.

#### Scenario: Subset font can't encode `[`

- WHEN the active font is a subset font whose forward map has no entry for `[` (U+005B) and the placeholder is `[PERSON: 7]`
- THEN the substitution at this match position MUST be SKIPPED (source bytes unchanged)
- AND the returned diagnostic surface MUST include `font_encoding_misses[$oid]["Jan Jansen"] = "<BaseFont value>"`
- AND `streams_modified` MUST NOT count this stream
- AND other matches in other streams MUST be unaffected

### REQ-004: Cross-CID-boundary matches SHALL NOT corrupt the stream

If a needle's match would start or end in the INTERIOR of a multi-codepoint ToUnicode mapping (i.e. the match boundary splits a single CID's resolved text), the substitution at that match position MUST be skipped and a `cid_split_mismatch` diagnostic MUST be added to the returned surface.

#### Scenario: Match would split a ligature CID

- WHEN a CID resolves to `"fi"` (two codepoints) and the needle is `"f"` (matches only the first codepoint of the CID)
- THEN the substitution MUST NOT fire
- AND the returned diagnostic surface MUST include `cid_split_mismatch[$oid]["f"]` with the offending CID position

## MODIFIED Requirements

### REQ-001: replaceTextInDocument diagnostic surface

`PDFDoc::replaceTextInDocument(array $substitutions)` MUST return an array with these keys (additive growth from the PoC's shape):

- `streams_scanned: int`
- `streams_modified: int`
- `replacements_per_needle: array<string, int>`
- `unmatched_needles: string[]`
- `font_encoding_misses: array<int, array<string, string>>` (new)
- `cid_split_mismatch: array<int, array<string, int>>` (new)

#### Scenario: WinAnsi-only fixture (PoC) still emits the original shape with empty additions

- WHEN `replaceTextInDocument(['Jan Jansen' => '[PERSON: 7]'])` is called on the WinAnsi PoC fixture
- THEN `streams_modified` MUST equal 1
- AND `replacements_per_needle['Jan Jansen']` MUST equal 1
- AND `font_encoding_misses` MUST be an empty array (no encoding issues on WinAnsi)
- AND `cid_split_mismatch` MUST be an empty array
