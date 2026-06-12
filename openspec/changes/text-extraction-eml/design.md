## Context

`TextExtractionService` (in `lib/Service/`) is OpenRegister's text-from-files extractor. The current cascade in `extractSourceText` covers PDF (smalot/pdfparser), Word documents (PhpWord), spreadsheets (PhpSpreadsheet), and plain-text MIMEs. EML files (`message/rfc822`) fall off the end — the cascade returns null. Downstream entity-recognition produces nothing for emails.

Two consumer needs drive the shape:

1. **Entity detection**: wants a single flat plain-text string. Current pattern across all extractors. Needs headers (which carry PII — sender / recipient names + emails) and body, plus the text content of any extractable attachment so detection sees a complete picture of the email's content.
2. **PDF rendering** (DocuDesk's paired `eml-pdf-assembly`): wants structured access to headers, both plain-text and HTML body parts, and the raw attachment bytes. HTML body is preferred for fidelity when the recipient sees the rendered email; plain-text body is the fallback. Attachments need to be embeddable as PDF/A-3 file attachments AND optionally rendered into pages.

A single flat-text return doesn't serve the second need. The change adds a structured-parse method alongside the flat extraction. Both delegate to a shared parser wrapping `zbateson/mail-mime-parser`.

The library choice rules out alternatives that add operational requirements:

- `php-mime-mail-parser/php-mime-mail-parser` requires the PECL `mailparse` extension, which isn't installed in many shared hosting / containerised setups.
- The PHP `imap` extension is deprecated for removal in PHP 8.4.
- Hand-rolled regex parsing on RFC 822 is a nest of edge cases; reliability would suffer.

`zbateson/mail-mime-parser` is pure PHP, modern, MIT-licensed, and well-tested. ~200KB on disk.

The capability scope follows the precedent set by `entity-relation-grondslagen`: spec ONLY the new EML extension; do not retroactively spec the broader `TextExtractionService` surface (PDF, Word, spreadsheet) which is implemented but currently uncovered by an OpenSpec capability. That broader retrofit is its own concern.

## Goals / Non-Goals

**Goals:**

- EML inputs to `TextExtractionService::extractFile()` produce populated plain-text — headers + body + recursively-extracted attachment text — instead of null.
- A new public method `parseEmlStructured()` returns a structured representation suitable for DocuDesk's `eml-pdf-assembly` to consume.
- Both flat and structured paths share one underlying parser. No code duplication.
- HTML body is preserved in the structured path (for high-fidelity downstream rendering); plain-text body remains the canonical input to entity detection.
- Recursive depth on nested EML attachments is bounded (depth 3) to defend against malicious nesting.

**Non-Goals:**

- Retrofit OpenSpec capability for the broader `TextExtractionService` surface.
- Decrypt S/MIME or PGP-encrypted EML bodies.
- Parse mailbox container formats (`mbox`, `pst`, `mst`) — those wrap EMLs and are a separate concern.
- Extract calendar invite bodies (`text/calendar`) — they're listed as attachments in v1.
- Configurable header inclusion in the flat plain-text path. Always-on; future toggle if needed.
- Async extraction. Synchronous in v1.
- Storage of structured parse results. The structured-parse method is read-on-demand; callers cache themselves if needed.

## Decisions

### D1. `zbateson/mail-mime-parser` over alternatives

Pure-PHP RFC 822 / MIME parser with no extension dependency. Handles malformed messages gracefully (real-world EMLs from production environments are often non-conforming; the parser tolerates this). MIT-licensed. ~200KB on disk; minimal dependency cost.

**Alternative considered:** `php-mime-mail-parser`. Faster (uses C-level `mailparse` PECL extension) but requires the extension to be installed. Many hosted Nextcloud setups won't have it. Rejected.

### D2. Flat plain-text path: headers + body + recursive attachments inline

The output structure for the existing extractor pattern (`extractEml` returns string) is fixed:

```
From: <header value>
To: <header value>
Cc: <header value>
Subject: <header value>
Date: <header value>

<body — plain text from text/plain, or HTML stripped to text>

--- Attachment: <filename> ---
<recursively-extracted text from the attachment via TextExtractionService>

--- Attachment: <filename> (<mimeType>, not extractable) ---
```

Extractable types for recursion: `application/pdf`, the Word MIMEs (DOCX/DOC), text MIMEs, `message/rfc822` (recursive EML). Anything else lists by name + MIME only.

**Rationale:** entity detection expects a single string. Inlining attachment text means PERSON / ORGANIZATION mentions inside attached PDFs are detected as part of the same document. Without inlining, attachments would produce no entities.

**Trade-off:** the flat-text path produces a longer string than the email body alone. Acceptable; entity detection scales linearly with input length and these strings are still small (typical email + attachment text is well under 1 MB).

### D3. Structured parse: `EmlStructure` value object

`parseEmlStructured(File)` returns an immutable value object:

```php
final class EmlStructure {
    public readonly array $headers;       // ['from' => string, 'to' => string[], 'cc' => string[], 'subject' => string, 'date' => DateTimeImmutable, 'messageId' => string|null, ...]
    public readonly EmlBody $body;        // ['plainText' => string|null, 'html' => string|null]
    public readonly array $attachments;   // EmlAttachment[]
}

final class EmlAttachment {
    public readonly string $filename;
    public readonly string $mimeType;
    public readonly string $content;      // raw bytes
    public readonly bool $isInline;
    public readonly ?string $contentId;   // for inline images referenced by HTML body
    public readonly ?EmlStructure $nestedEml;  // populated if mimeType === 'message/rfc822'
}
```

**Body shape:** both `plainText` and `html` populated when each part exists. Consumer chooses. The flat path picks `plainText`, falling back to HTML stripped to text. The PDF rendering path (DocuDesk's `eml-pdf-assembly`) picks `html` for fidelity, falling back to `plainText` wrapped in `<pre>`.

**Headers:** parsed and normalised. `to` and `cc` are arrays even when the original header has a single recipient (consumers don't have to special-case scalar vs array). `date` is a `DateTimeImmutable` parsed from RFC 2822 / 5322 date format; if the date is malformed, the field is null.

**Attachments:** ordered as encountered in the multipart structure. Inline attachments (referenced by `cid:` in HTML body) carry `contentId`; consumers rendering HTML can resolve them.

**Nested EML:** if an attachment is `message/rfc822`, the parser recursively builds an `EmlStructure` for it (subject to depth limit per D5). Consumers can walk the tree without re-parsing.

### D4. `EmlParser` is the shared underlying parser

Both `extractEml()` and `parseEmlStructured()` delegate to a shared `lib/Service/TextExtraction/EmlParser.php`:

```php
class EmlParser {
    public function parse(File $file): EmlStructure;          // primary parse — returns the rich object
    public function flatten(EmlStructure $structure): string; // build the flat plain-text from a structure
}
```

`extractEml` calls `parse()` then `flatten()`. `parseEmlStructured` calls `parse()` and returns the result directly. Single source of truth for parsing logic; flattening is a pure function of the structure.

**Rationale:** avoids two divergent parse paths drifting out of sync. Bug fixes (header normalisation, encoding handling, etc.) propagate to both consumers.

### D5. Recursive attachment depth limit: 3

Nested `message/rfc822` attachments are followed via `nestedEml` up to depth 3. Beyond that, the outermost attachment carries `nestedEml: null` even though it's an EML — consumers see the bytes but no structured recursion. The flat path follows the same limit: deeply nested EMLs are listed as attachments without further extraction.

**Rationale:** defends against malicious or accidental deep nesting (forwarded chains, archive-extracted threads). 3 levels covers realistic forwarding patterns ("forwarded forwarded message"); deeper would be a red flag.

**Trade-off:** legitimate deeper nesting (rare) loses structure. Acceptable; consumers can re-invoke `parseEmlStructured` on the bytes if they really need to go deeper.

### D6. HTML body stripped to text (for the flat path) — modest implementation

When `text/plain` is missing or empty in the flat-extraction path:

1. Take the `text/html` part.
2. Drop `<style>` and `<script>` content (block-level removal — content between opening and closing tags is removed).
3. Strip remaining tags via PHP's `strip_tags()`.
4. Decode HTML entities (`html_entity_decode`).
5. Collapse runs of whitespace into single spaces; preserve line breaks where the original HTML had `<br>`, `<p>`, or block-level elements (rough heuristic — replace these with newlines before stripping).

Not a full HTML→text conversion. Good enough for entity detection — names and email addresses survive. Layout fidelity is irrelevant here (the structured path preserves the HTML for proper rendering).

### D7. Header normalisation and encoding

Headers are decoded from RFC 2047 encoded-word form (`=?utf-8?b?...?=`) into plain UTF-8 strings. Non-ASCII bodies are normalised to UTF-8 via the parser's built-in handling. Malformed encoding is best-effort: the parser falls back to `mb_detect_encoding` + `mb_convert_encoding` to UTF-8.

**Edge case:** an EML with no `Date:` header. The structured `date` field is null; the flat path omits the line. Both paths tolerate the absence.

### D8. `extractFile` integration — additive cascade branch

`TextExtractionService::extractSourceText` (or its sibling cascade method) gains a branch for `message/rfc822`:

```
if (in_array($mimeType, $textMimeTypes) === true || strpos($mimeType, 'text/') === 0) { /* existing */ }
else if ($mimeType === 'application/pdf')                                              { /* existing */ }
else if ($this->isWordDocument(mimeType: $mimeType) === true)                          { /* existing */ }
else if ($this->isSpreadsheet(mimeType: $mimeType) === true)                           { /* existing */ }
else if ($mimeType === 'message/rfc822')                                               { return $this->extractEml($file); }   // NEW
else                                                                                   { /* existing fallthrough */ }
```

Cascade order kept consistent with existing pattern. EML appears after the heaviest existing branches, near the end.

## Risks / Trade-offs

- **[Malformed EMLs from production]** → Mitigation: `zbateson/mail-mime-parser` tolerates malformed input gracefully. The parser's defensive handling is one of the reasons it was chosen.
- **[Attachment recursion depth — denial-of-service]** → Mitigation per D5: depth limit 3.
- **[Large EMLs with many attachments — extraction time]** → Mitigation: recursive extraction reuses `TextExtractionService`'s existing per-type extractors; each is bounded; total time dominated by the single most expensive attachment. Documented in performance section. If problematic, follow-up to async processing.
- **[Encrypted EML]** → Out of scope. Encrypted body returns the encrypted blob's text representation (gibberish for entity detection); no decryption attempt. Future change handles S/MIME / PGP.
- **[Calendar attachments not text-extracted]** → Acceptable; calendars list as attachments by name + MIME. Future change adds a calendar branch to TextExtractionService if needed.
- **[Inline attachments referenced by `cid:` in HTML body]** → For DocuDesk's PDF rendering, inline images need to be accessible to the renderer. The structured path provides them via `EmlAttachment.contentId`; consumer resolves the reference. Documented in the structured-parse spec.
- **[Behaviour change for tenants relying on EML returning empty]** → Unlikely but possible. CHANGELOG entry under "Behavior changes" makes the new behaviour explicit. If a tenant truly wanted EML to be skipped, a follow-up could add a per-tenant config flag.

## Migration Plan

1. Land `zbateson/mail-mime-parser` in `composer.json`. CI / build pipeline picks it up.
2. Land `EmlStructure`, `EmlAttachment`, and `EmlParser` value-object / service classes.
3. Land the cascade branch in `TextExtractionService::extractSourceText` and the public `parseEmlStructured` method.
4. Land unit tests covering extraction paths and edge cases.
5. Release. Existing extracted-text records for EMLs (which were null) are not re-extracted automatically. New EML inputs produce populated text. Re-extraction is opt-in via the existing `forceReExtract` parameter.

**Rollback:** revert the cascade branch — EML inputs return null again. The new public method becomes an unused service method (no harm). `composer.json` revert removes the dep. Rollback is per-commit clean.

## Seed Data

Not applicable — this change adds a service method and a parser, not new schemas.

## Open Questions

- **Attachment-bytes lifetime in `EmlStructure`** — the structured parse holds attachment content in memory. For large EMLs (multi-megabyte attachments), this could pressure memory. Provisional: accept the cost in v1; if a real workload pushes against limits, follow-up to a streaming / lazy-load API where `content` is replaced with a callback.
- **`html2text` library vs hand-rolled stripping** — design D6 sketches a hand-rolled approach. Several PHP libraries do this better (e.g. `voku/html2text`, `html2text/html2text`). Provisional: hand-rolled is simpler for v1 (one fewer dep); switch to a library if entity detection misses entities due to poor stripping. Confirm at apply time.
- **Should `parseEmlStructured` be cached?** — the parse is non-trivial; if called repeatedly for the same file, the cost compounds. Provisional: rely on the consumer to cache (they hold the structure for as long as they need). If a use case emerges for service-level caching, follow-up.
- **Error handling on partial-parse failure** — `zbateson/mail-mime-parser` throws on truly broken input. Provisional: catch and log; return null from `extractEml` (preserving the existing pattern of "extraction failed → null"). For `parseEmlStructured`, throw a typed `EmlParseException`. Consumers handle as they see fit.
