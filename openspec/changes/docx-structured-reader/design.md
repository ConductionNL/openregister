## Context

See proposal.md for the why. `lib/Service/TextExtraction/` holds one class per format. `PresentationExtractor` (#4077) is the shape to match: a public `supports(mimeType, fileName)`, a public `extract(File $file): ?array`, a guard that throws when the zip extension is missing, per-document failures degraded to `null` with a content-free log line, and the XML work split out into a pure parser class. It reads the package through `OoxmlPackage`, which already bounds every part read (size cap without trusting the zip directory, DOCTYPE refused on the bytes and on the parsed tree, relationship targets resolved and climbs out of the package flagged external). None of that is presentation specific, so the document reader reuses it.

A `.docx` is an Office Open XML package (ECMA-376): `word/document.xml` holds the body, `word/styles.xml` the paragraph styles, `word/numbering.xml` the list definitions, `docProps/core.xml` the core properties, and `word/_rels/document.xml.rels` links the body to its images.

## Goals / Non-Goals

**Goals:**
- Match `PresentationExtractor`'s class shape, failure contract, bounds and logging discipline.
- Return structure learniq's onboarding can map one to one onto lesson blocks, so `DocxLessonReader` can be deleted in a follow-up.
- Keep the flat text byte-identical to what search indexes today.

**Non-Goals:**
- Returning image bytes. A consumer reads them by the returned package path, as for decks.
- Headers, footers, footnotes, endnotes and comments in the structure. They stay in the flat text, where `WordExtractor` already puts them; they are page furniture or asides, not lesson content.
- Legacy binary `.doc`, OpenDocument `.odt` and `.rtf`. Different formats; `.odt` is a candidate follow-up.
- Run formatting (bold, italic, colour), fields as fields, merged-cell geometry, numbering values ("3.2"), charts, equations and SmartArt text.
- Feeding the structure into the search pipeline. `TextExtractionService` keeps calling `WordExtractor` unchanged.

## Decisions

### Reuse `OoxmlPackage`, add pure classes

`DocumentExtractor` owns the file handling (temp file, `ZipArchive`, logging, the flat text) exactly as `PresentationExtractor` does. `DocumentBodyParser` walks the body into sections and blocks. `DocumentContentReader` reads one paragraph (text, pictures, text boxes) or one table (rows of cell text). `DocumentStyleMap` turns the styles and numbering parts into answers ("is this paragraph a heading, at which level?", "is this list numbered?"). `OoxmlElements` holds the local-name lookups the three share. All but the extractor are pure DOM work with no I/O. The split follows phpmd's class complexity cap of 50: one parser class came to 89.

Alternatives considered:
- Extend `WordExtractor` with a structured mode: it walks PhpWord's object model, which has already dropped the style ids, outline levels and image relationships this needs. Rejected.
- Read the structure through PhpWord's object model: PhpWord maps headings to `Title` elements only for styles it registered by name, keeps list numbering as its own style objects, and loads the whole document with no size bounds. Rejected; the package reader is small and bounded.
- Move learniq's `DocxLessonReader` over: it returns lesson-shaped sections with image bytes, markdown tables and `- ` list lines, trusts the zip directory's size, and reads text boxes twice. Its heading detection (style name, then outline level) is kept; its shape is not.

### The result shape

```
{
  title: "Water in de klas",
  sections: [
    { heading: "", level: 0, blocks: [ { type: "paragraph", text: "Groep 6, week 12" } ] },
    { heading: "Fotosynthese", level: 1, blocks: [
        { type: "paragraph", text: "..." },
        { type: "list", items: [ { text: "Licht", level: 1, ordered: false } ] },
        { type: "table", rows: [ ["Stof", "Rol"], ["CO2", "Bouwstof"] ] },
        { type: "image", target: "word/media/image1.png", external: false, name: "Blad", description: "..." }
    ] }
  ],
  text: "<what WordExtractor returns>",
  truncated: false
}
```

Sections are flat, one per heading, with the level kept, so a consumer that wants a tree can rebuild it and one that wants "one block per section" (learniq) needs no walk. Blocks are typed and ordered, because the order of a paragraph, a list and a picture under one heading is part of the lesson. A section with only a heading and no blocks is kept: an empty chapter is still a chapter. `ordered` sits on each list item rather than on the list, because Word lets level 1 be numbered and level 2 bulleted in one list.

### Headings, title and lists

- **Heading level**: the paragraph's own `w:outlineLvl` wins (0 to 8 is level 1 to 9, 9 is body text). Else the paragraph style is resolved through `styles.xml`: a style named `heading N` (case-insensitive, the name Word and LibreOffice write in every UI language, while the id is localised, `Kop1` in Dutch Word) gives level N; else the style's own `w:outlineLvl`; else its `basedOn` parent, up to 20 steps with a cycle guard. A style id missing from `styles.xml` that reads `HeadingN` falls back to level N, because a hand-made package often ships no styles part.
- **Title**: a style named `Title` (or the id `Title` without a styles part). The first one sets `title` and is not a block; a later one opens a level 1 section, as in learniq's reader. Without one, `dc:title` from the core properties part (found through the package relationship, else `docProps/core.xml`).
- **Heading beats list**: LibreOffice attaches chapter numbering to its heading styles (a `w:numPr` in the style, with the number format `none`). The heading check runs first, so those stay headings.
- **List items**: numbering comes from the paragraph's `w:numPr`, else from its style chain (Word's "List Bullet" style carries it there). `w:numId` 0 means "numbering removed". `ordered` is false for the formats `bullet` and `none` and for an unresolvable definition, true for every other format (`decimal`, `lowerLetter`, `upperRoman`, ...), read from `numbering.xml` via `w:num` to `w:abstractNum` to `w:lvl`. A new list block starts when the `numId` changes, so two adjacent lists stay two lists. Level overrides (`w:lvlOverride`) are not read: they change the start value or the glyph, rarely the kind.

### Paragraph text, text boxes and compatibility branches

One pass over a paragraph's descendants collects text (`w:t`, with `w:tab`, `w:br` and `w:cr` as spaces and `w:noBreakHyphen` as `-`), pictures and text boxes. The pass does not descend into `mc:Fallback` (the compatibility copy of a `mc:Choice`) or into `w:txbxContent`. Text box contents are then walked as block containers of their own and their blocks follow the host paragraph, one level deeper. Deleted text sits in `w:delText`, which the pass never reads. Elements are matched by local name and attributes by local name, so a transitional and a strict OOXML package read the same way, as in `PresentationSlideParser`.

### Tables

Each `w:tr` is a row and each `w:tc` a cell, as written, with its paragraphs joined by a newline; nested tables contribute their cell text to the enclosing cell, depth-bounded. Pictures inside a table become image blocks after the table block. Spans and vertical merges are not expanded: the cell count per row is what the document stores.

### Pictures

A `w:drawing` gives one image block per `a:blip` in it, with the name and alt text from its `wp:docPr` (falling back to the picture's own `cNvPr`). A legacy VML picture (`v:imagedata` inside `w:pict`) gives one image block with `o:title` as name and the shape's `alt` as alt text. `r:embed` and `r:id` resolve through the document part's relationships to a package path; `r:link` or a `TargetMode="External"` relationship stays a URL and is flagged `external`.

### The flat text

`text` is `WordExtractor::extract()` on the same file, so it is byte-identical to what search indexes. `WordExtractor` is injected through the constructor, so DI wires it and the tests can stand in for it. The structural read runs first, and the flat-text call only happens for a readable package. When `WordExtractor` returns `null` (PhpWord could not read a file the structural reader could) or throws because PhpWord is missing, `text` is built from the structure, one line per title, heading, paragraph, list item and table row (cells joined by a tab). This keeps the "one call gives both" promise without making the structure depend on PhpWord.

### Bounds

- Part reads through `OoxmlPackage`: 20 MiB per part (`MAX_PART_BYTES`), DOCTYPE refused on bytes and parsed tree, `LIBXML_NONET`.
- At most `MAX_BLOCKS` (10,000) paragraphs and tables are read; the result then says `truncated: true`. A 300 page textbook holds a few thousand.
- Content controls (`w:sdt`), custom XML wrappers, nested tables and text boxes stop descending at `MAX_DEPTH` (20).
- The `basedOn` chain stops at 20 steps and on a cycle.

### Failure contract

Same as `PresentationExtractor`: a missing zip extension throws; everything about one document (not a zip, no document part, a refused document part, nothing readable, an unsupported format) returns `null` and logs the file id, MIME type and exception class, never content. Refused part names are logged at warning level, as structure.

### Declarative-vs-imperative decision

Not applicable in the ADR-031 sense: no lifecycle, aggregation, calculation, notification, relation or widget is involved. Reading a document format is one of ADR-031's named imperative exceptions (document processing).

## Risks / Trade-offs

- [A hand-written reader misses a structure real documents use] → The spec pins the observable result, and the tests build documents with the structures that matter, including one in LibreOffice's shape and one with a localised Word style id. Unknown elements are skipped, not fatal. A local cross-check reads a document written by python-docx (Word's own default template).
- [LibreOffice Writer is not installed on the build box] → The LibreOffice-shaped test is hand-built from what LibreOffice 24.2 writes (heading styles with outline numbering and format `none`, a `TextBody` body style, list paragraphs with direct `w:numPr`, a text frame stored as `mc:AlternateContent` with the text in both branches, an anchored picture with alt text on `wp:docPr`). The PR says so.
- [Word's "List Bullet 2" style is its own list, not level 2 of "List Bullet"] → Word's default template gives each of those styles its own numbering instance at level 0, so an item in "List Bullet 2" comes back as a new list at level 1. That is how the file stores it; nesting is read from the list level, never guessed from the indent. The python-docx cross-check showed it.
- [The flat text call parses the document a second time] → Only for a package the structural reader already accepted; it is the same work search indexing does today. A consumer that wants only structure can ignore `text`; making the call optional is a later option, not needed by learniq.
- [Zip bomb or oversized XML] → Per-part read cap, block cap, depth caps, DOCTYPE refusal.

## Migration Plan

None. No schema, route, or dependency change. Rollback is reverting the PR; nothing calls the extractor yet.

## Seed Data

Not applicable: no OpenRegister schema is introduced or changed.
