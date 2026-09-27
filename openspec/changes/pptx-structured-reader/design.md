## Context

See proposal.md for the why. OpenRegister's extractors live in `lib/Service/TextExtraction/`, one class per format (`WordExtractor`, `PdfExtractor`, `SpreadsheetExtractor`, `EmlParser`). Each takes a `OCP\Files\File`, writes its bytes to a temp file, hands them to a library, and degrades a per-document failure to `null` with a content-free log line. `WordExtractor` is the shape to match: a constructor that takes only a `LoggerInterface`, a public `extract(File $file)`, a guard that throws when the library itself is missing, and private helpers.

A `.pptx` is an Office Open XML package (ECMA-376): a zip holding `ppt/presentation.xml` (the slide list), one `ppt/slides/slideN.xml` per slide, optional `ppt/notesSlides/notesSlideN.xml`, and `_rels/*.rels` relationship parts that link them together and point at `ppt/media/*`.

## Goals / Non-Goals

**Goals:**
- Match `WordExtractor`'s class shape, failure contract and logging discipline.
- Return structure a consumer can map one to one onto lesson blocks, without the consumer knowing OOXML.
- Treat every uploaded deck as hostile input.

**Non-Goals:**
- Returning image bytes. The consumer reads them from the original file through OpenRegister's file layer when it needs them; the extractor only says where they are.
- Legacy binary `.ppt` and OpenDocument `.odp`. Both are different formats; `.odp` is a candidate follow-up.
- Feeding presentations into the flat search-indexing pipeline (`TextExtractionService`). That changes existing indexing behaviour, so it is its own change.
- Layout, theme, animation, transitions, charts and SmartArt text.

## Decisions

### Read the package with `ZipArchive` and `DOMDocument`, not `phpoffice/phppresentation`

The brief named `phpoffice/phppresentation` as a new dependency with a caret range and no major bump elsewhere. That cannot be met today: `composer require "phpoffice/phppresentation:^1.2" --dry-run` fails because 1.2.0, the newest tag, requires `phpoffice/phpspreadsheet ^1.9 || ^2.0 || ^3.0 || ^4.0`, while OpenRegister requires `^5.0` (locked 5.10.0) and carries a local patch against it (`patches/phpspreadsheet-zipstream3-prefer.patch`). The library's `dev-master` accepts `^5.0`, but it is unreleased.

Alternatives considered:
- `dev-master` pinned to a commit: not a caret range, no release notes, no security advisories keyed to a version. Rejected.
- Downgrade phpspreadsheet to 4.x: a major change to a patched dependency other code relies on. Rejected by the brief.
- Extend filinq's `PptxPresentationCodec`: it edits shapes in place, lives in another app, and would make OpenRegister depend on filinq. Rejected, as the recon already recommended.
- Read the package directly: the structure needed here (slide order, placeholder types, paragraphs, notes body, picture relationships) is a small, stable part of ECMA-376. `ZipArchive` and `DOMDocument` are already used in OpenRegister (`DocumentProcessingHandler`, `SipPackageBuilder`) and add no dependency. **Chosen.**

The public result does not expose the parser, so the internals can switch to `phppresentation` once a release accepts phpspreadsheet 5, without touching a consumer.

### The result shape

```
{
  slides: [
    { number: 1, hidden: false, title: "...", body: ["...", "..."], notes: "...",
      images: [{ target: "ppt/media/image1.png", external: false, name: "...", description: "..." }] }
  ],
  truncated: false
}
```

`number` is the position in the deck, not the file name, because a deck that was reordered in PowerPoint keeps its old file names. `body` is a list of paragraphs, not one string, because a consumer turns each into a list item or a sentence. `images` carries package paths so the consumer can link the original file as a `Material` without the extractor holding bytes in memory.

### Title, body and notes by placeholder type

A shape is a title when its placeholder type is `title` or `ctrTitle`. Every other text-bearing shape (`p:sp` with `p:txBody`, including subtitles and untyped placeholders) goes to `body`, and so do table cells in a `p:graphicFrame`. Group shapes (`p:grpSp`) are walked in place. On a notes page only the `body` placeholder is notes; the slide image, slide number, header, footer and date placeholders are skipped.

### Relationships resolve relative to the part

Targets in `ppt/slides/_rels/slide1.xml.rels` are relative to `ppt/slides/` (`../media/image1.png` resolves to `ppt/media/image1.png`). A small path normaliser handles `.` and `..`; a target that climbs above the package root, or a `TargetMode="External"` link, is kept as given and flagged `external` rather than resolved.

### Bounds

- A part is read with `ZipArchive::getFromName($name, MAX_PART_BYTES + 1)`. A longer read is treated as unreadable, which does not trust the size the zip directory claims.
- Any XML part containing `<!DOCTYPE` is refused before parsing, which closes entity expansion and external entity loading together. Parsing uses `LIBXML_NONET`, and libxml errors are collected, not printed.
- At most `MAX_SLIDES` slides are read; the result then says `truncated: true`.
- Group descent stops at `MAX_GROUP_DEPTH`.

### Failure contract

Same as `WordExtractor`: a missing zip extension throws (a deployment error that must be loud); everything about one document (not a zip, no presentation part, no slides, an unsupported format) returns `null` and logs the file id, MIME type and exception class at error level, never content.

### Declarative-vs-imperative decision

Not applicable in the ADR-031 sense: no lifecycle, aggregation, calculation, notification, relation or widget is involved. Reading a document format is one of ADR-031's named imperative exceptions (document processing).

## Risks / Trade-offs

- [A hand-written reader misses a structure real decks use] → The spec pins the observable result, and the test builds decks with the structures that matter (placeholders, groups, tables, notes, pictures, hidden slides, reordered slide files). Unknown elements are skipped, not fatal.
- [A deck built by another tool uses a different notes layout] → Notes are found through the slide's relationship of type `notesSlide`, the way the standard defines it, not through file names.
- [Zip bomb or oversized XML] → Per-part read cap, slide cap, DOCTYPE refusal.
- [The brief expected a new dependency] → The PR states the deviation and the reason at the top; the swap to `phppresentation` later is internal.

## Migration Plan

None. No schema, route, or dependency change. Rollback is reverting the PR; nothing calls the extractor yet.

## Seed Data

Not applicable: no OpenRegister schema is introduced or changed.
