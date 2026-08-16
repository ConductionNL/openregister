## MODIFIED Requirements

### Requirement: Replacement output MUST use identifiable placeholders, not pure redaction

Hard constraint #2: replacements MUST take the form `[<TYPE>: <id>]` (the established convention from `entity-relation-grondslagen`). Pure black-bar redaction is ruled out. All variants of one logical entity MUST resolve to the same placeholder text (same id) — the substitution map already enforces this; this Requirement locks the invariant at the spec level so future maintainers don't break it for layout reasons.

Both `<TYPE>` and `<id>` are whatever the upstream substitution map carries for the entity. As of `anonymisation-placeholder-id-scope`, `<id>` is a **scope-local sequence number** (per-document by default, per-dossier when opted in), NOT the global `openregister_entities.id`; and `<TYPE>` is a **localized label** in the acting user's language (e.g. `PERSOON` on a Dutch instance), NOT necessarily the English type. The PDF replacer is agnostic to how either is computed — it MUST faithfully emit the placeholder text supplied in the substitution map without re-deriving, re-numbering, or re-translating. Only the upstream map changes; the PDF replacement contract is unchanged beyond this clarification.

#### Scenario: Placeholder format follows `[<TYPE>: <id>]`

- **GIVEN** an entity with type `PERSON`, the scope-local id `1` in the substitution map, and value `"Jan Jansen"`
- **WHEN** `anonymizeDocument` replaces this entity in a PDF
- **THEN** every replacement instance in the output text reads `[PERSON: 1]` (case-sensitive, with a space after the colon)
- **AND** the replacer MUST emit exactly the id from the map — it MUST NOT substitute the global entity id

#### Scenario: Variants of one entity share one placeholder

- **GIVEN** an entity with scope-local id `1`, value `"Jan Jansen"`, variants `["Jansen", "Jan"]`
- **WHEN** `anonymizeDocument` replaces these in a PDF containing all three
- **THEN** every replacement (regardless of which variant matched) reads `[PERSON: 1]`
- **AND** adjacent identical placeholders separated only by whitespace / dashes / underscores ARE collapsed to a single placeholder

### Requirement: The output MUST NOT contain any original entity text in any PDF layer

"No original PII in the output" is hard constraint #1. The implementation MUST ensure that for every entity value in the substitution map (including all variants — full name, surname-only, first-initial-plus-surname, etc.), the value is absent from EVERY layer of the output PDF:

- Visible text layer (content streams via Tj/TJ operators).
- Hidden text layer (text rendered with zero opacity or behind a covering rectangle — same content-stream operators, different render mode).
- Document metadata (`/Info` dictionary fields, `/Metadata` XMP stream).
- Bookmark / outline entries (`/Outlines`).
- Annotation text contents (`/Annots → /Contents`).

Visual-overlay-only approaches (paint a black rectangle over the text) are explicitly ruled out.

#### Scenario: Re-extraction of the output finds no entity text

- **GIVEN** a substitution map containing `"Jan Jansen"`, `"Jansen"`, `"Jan"` (all variants of one entity, all mapped to the scope-local placeholder `[PERSON: 1]`)
- **AND** an input PDF where each variant appears at least once in the text layer
- **WHEN** `anonymizeDocument` completes successfully
- **THEN** re-extracting the output PDF via `smalot/pdfparser` returns text that contains NONE of the three variants
- **AND** the extracted text DOES contain `[PERSON: 1]`
