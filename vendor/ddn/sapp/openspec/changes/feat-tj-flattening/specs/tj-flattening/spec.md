**Status**: planned
**Scope**: change `feat-tj-flattening` (delta spec)
**OpenSpec changes**:
- `feat-tj-flattening` (in-progress)

## Purpose

Capability contract for `tj-flattening` — the normative SHALL/MUST
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
`openspec/specs/tj-flattening/spec.md` after `/opsx-archive`. The
delta operations below (`## ADDED Requirements`, `## MODIFIED
Requirements`) are merged into the canonical spec by the archiver.

## ADDED Requirements

### REQ-001: Tokeniser SHALL emit per-fragment entries for TJ operators

When the content-stream tokeniser encounters a `TJ` operator, it MUST emit one virtual text-showing entry per string fragment in the array. Numeric kerning entries MUST be preserved but exposed as non-text-showing entries (so they don't participate in the text-space concatenation).

#### Scenario: TJ with multiple fragments

- GIVEN the operator is `[(Hello) -10 (World)] TJ`
- WHEN `processTjArray` is invoked
- THEN the tokeniser MUST emit 2 text-showing fragments: `(Hello)` and `(World)`
- AND preserve the kerning value `-10` between them
- AND mark both fragments with the same `parent_tj_index`

#### Scenario: TJ with mixed literal + hex strings

- GIVEN the operator is `[<0041> 0 (B) -5 <0043>] TJ`
- WHEN `processTjArray` is invoked
- THEN the tokeniser MUST emit 3 fragments and normalise both literal and hex strings to byte arrays
- AND preserve kerning values 0 and -5 between them

#### Scenario: Empty TJ array

- GIVEN the operator is `[] TJ`
- WHEN `processTjArray` is invoked
- THEN the tokeniser MUST emit no fragments and SHOULD emit a `p_debug` log line

### REQ-002: Matching across TJ fragment boundaries SHALL succeed

The text-space matcher MUST treat a TJ operator's fragments as a single contiguous text run for matching purposes. A needle that spans 2+ fragments MUST match.

#### Scenario: Needle spans 4 fragments of a TJ

- WHEN the content stream contains `[(J) 2 (a) -1 (n) 3 ( ) -2 (Jansen)] TJ` and the needle is `"Jan Jansen"`
- THEN the matcher MUST identify the match as covering all 5 fragments (J, a, n, space, Jansen)
- AND report this in the matched-fragments span for the splicer

### REQ-003: Splicer SHALL produce the correct shape per match position

Given a match span covering fragments `[m_start, m_end]` within a `TJ` array of fragments `[0, N-1]`, the splicer MUST produce one of four output shapes per Decision D2:

| Case | Shape |
|------|-------|
| Full TJ matched (`m_start == 0 && m_end == N-1`) | `(placeholder) Tj` |
| Prefix matched (`m_start == 0`) | `(placeholder) Tj [<remaining>] TJ` |
| Suffix matched (`m_end == N-1`) | `[<leading>] TJ (placeholder) Tj` |
| Middle matched | `[<leading>] TJ (placeholder) Tj [<trailing>] TJ` |

#### Scenario: Full TJ match becomes Tj

- GIVEN the operator is `[(Jan ) -2 (Jansen)] TJ` and the entire array matches the needle
- WHEN `processTjArray` is invoked
- THEN the spliced output MUST be `(<placeholder bytes>) Tj` with no surrounding TJ

#### Scenario: Prefix match preserves trailing TJ

- GIVEN the operator is `[(Jan Jansen) -5 ( voor het loket.)] TJ` and the needle matches only `"Jan Jansen"` (fragment 0)
- WHEN `processTjArray` is invoked
- THEN the spliced output MUST be `(<placeholder>) Tj [(<trailing fragment>)] TJ` with kerning `-5` preserved inside the trailing TJ

#### Scenario: Middle match produces three operators

- GIVEN the operator is `[(Voor ) 2 (Jan Jansen) -3 ( voor het loket.)] TJ` and the needle matches the middle fragment only
- WHEN `processTjArray` is invoked
- THEN the spliced output MUST be `[(Voor )] TJ (<placeholder>) Tj [( voor het loket.)] TJ`
- AND the kerning `2` between fragments 0 and 1 MUST be discarded (it was inside the match span boundary)
- AND the kerning `-3` between fragments 1 and 2 MUST be discarded

### REQ-004: TJ matching SHALL honour CID-boundary alignment

If a needle's match would start or end in the interior of a multi-codepoint CID inside a TJ fragment, the substitution MUST be skipped and `cid_split_mismatch` MUST be recorded (same rule as `feat-tounicode-cmap`).

#### Scenario: CID-split inside a TJ fragment

- WHEN a TJ fragment contains a single CID resolving to `"fi"` and the needle is `"f"` (would split the CID)
- THEN the substitution MUST NOT fire and a `cid_split_mismatch` diagnostic MUST be added

### REQ-005: TJ parser SHALL adhere to PDF 1.7 tokenisation rules

The TJ array content parser MUST honour the spec-defined byte alphabet for each token type:

1. Whitespace per PDF 1.7 §7.2.3 Table 1: NUL, HT, LF, FF, CR, SP only (no `\v` etc).
2. `%`-to-end-of-line comments per §7.2.4 MUST be treated as whitespace-equivalent and skipped.
3. Numeric Objects per §7.3.3: ONLY `-+.0-9` are valid. Exponent notation (`e`/`E`) MUST NOT be accepted in the numeric tokenizer — accepting it would let a producer's `e` glyph token (after a hex CID `<65>`) be swallowed as part of a number and desync the byte-offset alignment.
4. Hexadecimal strings per §7.3.4.3: odd-length hex MUST be implicitly padded with a trailing `0`; the parser MUST NOT silently drop the unpaired character.

#### Scenario: Numeric tokenizer rejects exponent notation

- GIVEN a TJ array `[<65> 1e2 (foo)]` where the producer intended `1e2` to be a glyph-show + kerning sequence (not the IEEE-style 100)
- WHEN `parseTjArrayContent` is invoked
- THEN the numeric tokenizer MUST stop at the `e` and emit `1` as the kerning value; the `e2` MUST be classed as an unexpected token and the parse MUST bail (return null)

#### Scenario: Odd-length hex padded with trailing zero

- GIVEN a TJ array containing `<41B>` (3 hex digits)
- WHEN `parseTjArrayContent` is invoked
- THEN the fragment MUST decode to the 2-byte string `\x41\xB0` (the trailing `B` paired with implicit `0`)

#### Scenario: Comments inside TJ arrays are whitespace-equivalent

- GIVEN a TJ array containing `(A) % kerning suppressed for layout\n -2 (B)`
- WHEN `parseTjArrayContent` is invoked
- THEN the parser MUST skip the comment and emit `[text:A, kern:-2, text:B]`

### REQ-006: Multiple matches in the same TJ SHALL each be spliced

When a single TJ array contains multiple matches (multiple needles OR the same needle twice across different fragment spans), the splicer MUST process all of them in one pass — earlier code returned on the first match and silently under-counted `replacements_per_needle`.

#### Scenario: Two needles match in the same TJ

- GIVEN a TJ array whose concatenated text is `"Jan Jansen and Karel Karelsen"` (kerning split arbitrarily across fragments) and substitutions `["Jan Jansen" => "[A]", "Karel Karelsen" => "[B]"]`
- WHEN `processTjArray` is invoked
- THEN the output MUST contain BOTH `[A]` and `[B]` Tj operators
- AND `replacements_per_needle["Jan Jansen"]` MUST equal 1
- AND `replacements_per_needle["Karel Karelsen"]` MUST equal 1
- AND `tj_arrays_modified` MUST equal 1 (one TJ modified, two splices in it)

#### Scenario: Same needle twice in the same TJ

- GIVEN a TJ array whose concatenated text is `"Jan Jansen and Jan Jansen"` and substitution `["Jan Jansen" => "[A]"]`
- WHEN `processTjArray` is invoked
- THEN the output MUST contain TWO `[A]` Tj operators
- AND `replacements_per_needle["Jan Jansen"]` MUST equal 2

## MODIFIED Requirements

### REQ-001: replaceTextInDocument diagnostic surface

The diagnostic surface returned by `replaceTextInDocument` MUST grow with a new key `tj_arrays_modified: int` counting the number of TJ operators that had at least one match-driven splice.

- `streams_scanned: int`
- `streams_modified: int`
- `replacements_per_needle: array<string, int>`
- `unmatched_needles: string[]`
- `font_encoding_misses: array<int, array<string, string>>`
- `cid_split_mismatch: array<int, array<string, int>>`
- `tj_arrays_modified: int` (new)

#### Scenario: TJ flattening modifies one operator

- WHEN a single TJ operator matches one needle and is spliced
- THEN `tj_arrays_modified` MUST equal 1
- AND `streams_modified` MUST equal 1
- AND `replacements_per_needle[needle]` MUST equal 1
