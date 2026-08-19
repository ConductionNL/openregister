**Status**: planned
**Scope**: change `feat-text-replacement-api` (delta spec)
**OpenSpec changes**:
- `feat-text-replacement-api` (in-progress)

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

### REQ-001: replaceTextInDocument SHALL validate input parameters

The polished API MUST validate input parameters and reject the following with `p_error` + skipping the offending substitution (other substitutions in the same call proceed):

- Empty-string needles (would match everywhere)
- Placeholders containing control characters (`\x00`–`\x1F`)
- Placeholders containing PDF-string-escape-significant characters (`(`, `)`, `\`) — out of scope for this version; can be re-enabled when a use case demands

#### Scenario: Empty-string needle is rejected

- WHEN `replaceTextInDocument(['' => 'placeholder'])` is called
- THEN `p_error` MUST be called identifying the empty-string needle
- AND the substitution MUST NOT fire
- AND the method MUST return its normal diagnostic shape with `replacements_per_needle` excluding the rejected entry

#### Scenario: Placeholder containing `\` is rejected

- WHEN `replaceTextInDocument(['Jan Jansen' => 'foo\\bar'])` is called
- THEN `p_error` MUST be called identifying the offending placeholder
- AND the `Jan Jansen` substitution MUST NOT fire
- AND other substitutions in the same call MUST proceed normally

#### Scenario: Valid call processes all substitutions

- WHEN `replaceTextInDocument(['Jan Jansen' => '[PERSON: 7]', 'Acme B.V.' => '[ORG: 3]'])` is called with both valid
- THEN both substitutions MUST be attempted
- AND the returned `replacements_per_needle` MUST include both keys

### REQ-002: Diagnostic surface SHALL be stable and documented

The returned array MUST contain exactly these keys with the documented semantics. The API contract is frozen at this point (this is the upstream-submission shape).

| Key | Type | Semantics |
|---|---|---|
| `streams_scanned` | int | Total content streams visited (regardless of match) |
| `streams_modified` | int | Streams with ≥ 1 spliced match |
| `replacements_per_needle` | `array<string, int>` | Per-needle match count summed across all streams |
| `unmatched_needles` | `string[]` | Needles with zero matches across the entire document |
| `tj_arrays_modified` | int | TJ operators that had at least one match-driven splice |
| `subset_font_fallbacks_used` | int | Placeholder emissions that used the fallback font |
| `font_encoding_misses` | `array<int, array<string, string>>` | `[oid => [needle => font_name]]` for matches both active font + fallback couldn't encode |
| `cid_split_mismatch` | `array<int, array<string, int>>` | `[oid => [needle => cid_position]]` for matches that would split a CID interior |
| `rejected_substitutions` | `array<string, string>` | `[needle => reason]` for input-validation rejections |

#### Scenario: All keys present even when zero values

- WHEN `replaceTextInDocument` is called with a single valid substitution that produces no matches
- THEN the returned array MUST contain ALL 9 keys
- AND counters MUST be 0 / empty arrays where there's no data

### REQ-003: Idempotency across repeat calls

Calling `replaceTextInDocument` twice on the same `PDFDoc` instance MUST be safe: the second call MUST NOT re-inject a fallback font that's already present, MUST NOT match its own previously-inserted placeholders, and MUST return diagnostic counts reflecting only the second call's matches.

#### Scenario: Repeat call doesn't re-inject fallback

- WHEN `replaceTextInDocument` is called twice with substitutions that each trigger the Helvetica fallback
- THEN the page's `/Resources/Font/F-anonymisation-fallback` MUST be set exactly once (the first call's injection survives)
- AND the second call MUST NOT create a duplicate Helvetica font object

#### Scenario: Repeat call doesn't double-replace

- WHEN the first call replaces `Jan Jansen` → `[PERSON: 7]` (1 match)
- AND the second call uses the same substitutions
- THEN the second call's `replacements_per_needle['Jan Jansen']` MUST equal 0 (the source bytes are now the placeholder, not the needle)
- AND `streams_modified` MUST equal 0 for the second call

## MODIFIED Requirements

### REQ-001: replaceTextInDocument SHALL match the needle in text space, not byte space

Unchanged contract from `feat-tounicode-cmap`. Listed here for completeness — this PR adds parameter validation and stable diagnostic shape on top of the existing text-space matching.

#### Scenario: WinAnsi-only fixture (PoC regression) still produces clean output

- WHEN `replaceTextInDocument(['Jan Jansen' => '[PERSON: 7]'])` is called on `examples/poc-fixture.pdf`
- THEN the returned diagnostic surface MUST satisfy: `streams_modified == 1`, `replacements_per_needle['Jan Jansen'] == 1`, `unmatched_needles == []`, `subset_font_fallbacks_used == 0`, `font_encoding_misses == []`, `cid_split_mismatch == []`, `rejected_substitutions == []`, `tj_arrays_modified == 0`
- AND the re-extracted output MUST have `residual_needles == 0` and `placeholder_hits == 1`

### REQ-002: Placeholder SHALL be emitted via the active font's forward map

Unchanged in the no-fallback path; this PR adds the fallback recovery (see `subset-font-fallback` capability). Listed here so the full contract is reviewable in one place at archive time.

#### Scenario: Active font encoding works → no fallback

- WHEN the active font's forward map can encode every character of the placeholder
- THEN the placeholder MUST be emitted via the active font (NOT the fallback)
- AND the page's `/Resources/Font` MUST NOT be modified
