**Status**: planned
**Scope**: change `feat-text-replacement-api` (delta spec)
**OpenSpec changes**:
- `feat-text-replacement-api` (in-progress)

## Purpose

Capability contract for `subset-font-fallback` — the normative SHALL/MUST
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
`openspec/specs/subset-font-fallback/spec.md` after `/opsx-archive`. The
delta operations below (`## ADDED Requirements`, `## MODIFIED
Requirements`) are merged into the canonical spec by the archiver.

## ADDED Requirements

### REQ-001: Fallback Helvetica font SHALL be injected when the active font fails

When the splicer's encoding step fails (active font's forward map can't encode every character of the placeholder), it MUST inject a `/Helvetica` Type1 resource into the affected page's `/Resources/Font` dictionary (idempotent — at most once per page) and emit the placeholder under that font.

#### Scenario: Subset font missing `[` triggers fallback

- WHEN the active font's forward map can encode `PERSON: 7` but not `[` or `]`, and the placeholder is `[PERSON: 7]`
- THEN a `/Helvetica` Type1 font object MUST be created via `PDFDoc::create_object`
- AND `/F-anonymisation-fallback` MUST be added to the page's `/Resources/Font`
- AND the spliced content stream MUST emit `q\n/F-anonymisation-fallback 12 Tf\n(<placeholder>) Tj\nQ`
- AND `subset_font_fallbacks_used` MUST equal 1 in the returned diagnostic surface

#### Scenario: Fallback font injected once per page across multiple matches

- WHEN the same page contains three matches that all require the fallback
- THEN the Helvetica object MUST be created exactly once
- AND the page's `/Resources/Font/F-anonymisation-fallback` MUST be set exactly once
- AND `subset_font_fallbacks_used` MUST equal 3 (one per spliced placeholder)

#### Scenario: Inherited Resources are promoted to page-level on fallback

- WHEN a page inherits its `/Resources` from a parent node and has no own `/Resources` entry
- AND a match on that page requires the fallback
- THEN the inherited Resources dictionary MUST be copied to the page's own `/Resources` BEFORE the fallback font is added
- AND the parent's Resources dictionary MUST remain unchanged (sibling pages are unaffected)

#### Scenario: Resource name collision SHALL be detected

- WHEN a page already has a font resource named `/F-anonymisation-fallback` (vanishingly unlikely but spec-legal)
- THEN the injector MUST use the next available variant (`/F-anonymisation-fallback-2`, `/F-anonymisation-fallback-3`, ...)
- AND the injected resource name MUST be the one used in the spliced content stream's `Tf` operator

### REQ-002: Fallback emission SHALL preserve surrounding graphics state

The spliced content stream's `q ... Q` pair MUST be syntactically correct (matched push/pop) and MUST be the ONLY graphics-state mutation in the placeholder splice. Operators AFTER the `Q` MUST observe the same graphics state as operators BEFORE the `q`.

#### Scenario: Operators after the placeholder see the original font

- WHEN the active font before the splice was `/F1` and the splice emits the fallback placeholder
- THEN the next operator after `Q` MUST operate under `/F1` (verifiable by tokenising the spliced stream and checking the active Tf state at that operator's index)

### REQ-003: Fallback SHALL ONLY fire when the active font fails

The fallback path MUST NOT be the default. The splicer MUST first attempt to encode the placeholder through the active font's forward map. Only when that returns null/unencodable for at least one character does the fallback engage.

#### Scenario: Active font that can encode placeholder MUST NOT trigger fallback

- WHEN the active font is `/WinAnsiEncoding` Helvetica (built-in, can encode the entire placeholder character set)
- AND the placeholder is `[PERSON: 7]`
- THEN the placeholder MUST be emitted via the active font (not the fallback)
- AND the fallback Helvetica resource MUST NOT be injected into the page's `/Resources`
- AND `subset_font_fallbacks_used` MUST equal 0

## MODIFIED Requirements

### REQ-001: Unencodable placeholders SHALL be diagnosed, not corrupted

Replaces the `feat-tounicode-cmap` rule. The active font's forward map is tried first; if it can't encode every character, the fallback (`feat-text-replacement-api`) is tried. If BOTH fail (active subset font missing characters AND placeholder contains characters outside Helvetica WinAnsiEncoding), the substitution at this position MUST be skipped and `font_encoding_misses` MUST be populated as before.

#### Scenario: Placeholder uses a character outside both fonts

- WHEN the active font is a subset Helvetica missing `€` (U+20AC) and the placeholder contains `€`
- AND the fallback Helvetica `/WinAnsiEncoding` (which DOES include `€` at byte 0x80) is also available
- THEN the fallback MUST be used (WinAnsi has `€` even when the subset doesn't)
- AND the substitution MUST succeed

#### Scenario: Placeholder uses a character outside both fonts (e.g. Hebrew)

- WHEN the placeholder contains a character that's neither in the subset font NOR in Helvetica's `/WinAnsiEncoding` (e.g. U+05D0 Hebrew Alef)
- THEN the substitution MUST be skipped
- AND `font_encoding_misses[$oid][$needle] = $font_base_name` MUST be recorded
- AND `subset_font_fallbacks_used` MUST NOT be incremented for this match
