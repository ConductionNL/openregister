## Context

OpenRegister's anonymisation pipeline relies on PhpWord's high-level walker to mutate text in `.docx` files. The walker handles run-level text replacement well but is structurally blind to:

- **Comments** (`word/comments.xml` + inline `commentRangeStart` / `commentRangeEnd` / `commentReference` markers). PhpWord 1.3 exposes `getComments()` for read access but lacks a clean removal API.
- **Tracked changes** (`<w:ins>` / `<w:del>` wrappers with author attributes). PhpWord does not model these as first-class objects; on round-trip write, PhpWord silently drops some — but the loss is unreliable and undocumented, and it doesn't strip the `customXml/people.xml` part that carries reviewer identities.
- **Revision history / version markers** (`w:rsid*` attributes, document-level revision tracking elements).
- **Document metadata** (`docProps/core.xml`, `docProps/app.xml`, `docProps/custom.xml`) — Author, Last-Modified-By, Title, Subject, Manager, Company, Keywords, custom properties. Frequently contains real human names.
- **Person-identifying field codes** (`{AUTHOR}`, `{USERNAME}`, `{USERINITIALS}`, `{LASTSAVEDBY}`). Stored as `<w:fldSimple w:instr=" AUTHOR ">` or the multi-element `<w:fldChar>` / `<w:instrText>` form. Cached result text leaks the resolved value.
- **Custom XML parts** (`customXml/item*.xml`) — companies use these to embed structured data via Word Content Controls. Often contains far more PII than the visible document (CRM-templated letters with full addressee records).
- **Hyperlinks** — visible text gets walker-anonymised but the URL in `_rels/document.xml.rels` survives. URLs often carry PII (`mailto:p.jansen@…`, query strings with case numbers).

OpenDocument Text (`.odt`) has analogous structures (`office:annotation`, `text:tracked-changes`, `meta.xml` fields, `text:a xlink:href` hyperlinks) and is not currently supported by the pipeline at all — `TextExtractionService` returns null for ODT, and `DocumentProcessingHandler` dispatches it to a raw-text replacer that corrupts the ZIP container.

The cleanest fix is **XML-level surgery on a temp copy** of the file, ahead of the entity-anonymisation walker. PhpWord round-trip is rejected because it would either over-strip (losing styles and formatting) or under-strip (leaving orphan content-type references). Native ZIP-and-XML manipulation gives surgical control with no fidelity loss.

The originals are preserved by design: the sanitiser operates on a temp file copy and returns a path to the sanitised output. The caller is responsible for placing the output wherever its convention dictates (anonymisation flow → dossier folder; future use cases → wherever they want).

## Goals / Non-Goals

**Goals:**

- A new `OfficeDocumentSanitizer` produces a sanitised derivative of a `.docx` or `.odt` input. The original file is untouched.
- The sanitised output opens cleanly in Microsoft Word (current LTSC and 365 channels) AND LibreOffice (24.x or later) without "found unreadable content" recovery warnings.
- The sanitiser removes / scrubs the categories enumerated in Context above: comments, tracked changes, revision history, metadata, person field codes, custom XML, hyperlinks (flatten).
- A `SanitizationReport` value object captures per-category counts; persisted on the anonymisation log row for audit.
- Sanitisation is invoked transparently by `DocumentProcessingHandler::anonymizeDocument` for DOCX / ODT inputs.
- ODT (the format) is handled symmetrically with DOCX — no second-class status.

**Non-Goals:**

- Deeper walker traversal (tables / lists / headers / footers / footnotes / endnotes). The sister change `text-extraction-office-completeness` covers this. Sanitiser preserves these structures untouched.
- Word "Document Inspector" feature parity. Inspector covers ink, smart tags, embedded objects, etc. — not in this change.
- Reverse operation (un-sanitise). One-way only; original is the source of truth for the unsanitised content.
- Encrypted document handling. Password-protected DOCX / ODT raises `SanitizationException`; caller decides fallback.
- Sanitiser configuration knobs (per-tenant on/off, per-category toggles). v1 always-on, all categories. Configuration is a follow-up if real demand emerges.
- Streaming / partial processing. v1 reads the whole ZIP container, mutates, writes. For 100MB+ documents this is memory-pressured; acceptable in v1 (Office docs are rarely that large in practice).

## Decisions

### D1. XML surgery on a temp ZIP copy (not PhpWord round-trip)

Sanitiser opens the input file as a `ZipArchive`. For each XML part that needs surgery (`word/document.xml`, `word/comments*.xml`, `word/header*.xml`, `word/footer*.xml`, `word/footnotes.xml`, `word/endnotes.xml`, `docProps/*.xml`, `_rels/*.rels`, `[Content_Types].xml`, `customXml/*.xml`), it:

1. Reads the part as a string.
2. Loads into a `DOMDocument` with namespace-aware processing.
3. Performs XPath-driven node removal / mutation.
4. Serialises back to XML string.
5. Writes the new string into the temp ZIP under the original part name.

Parts that are removed entirely (`word/comments.xml`, `customXml/*`) are also removed from `[Content_Types].xml` (the `Override` element for that part path) AND from `_rels/document.xml.rels` (the `Relationship` element for that target).

**Alternative considered:** PhpWord round-trip (read → modify object model → write). Rejected because:
- PhpWord drops formatting and custom XML on write — unacceptable fidelity loss.
- PhpWord has no comments / tracked-changes mutation API; surgery would still need ZIP-level access.
- Two-pass (PhpWord then ZIP) doubles the work without clear benefit.

**Alternative considered:** `phpoffice/phpword`-internal `PhpOffice\PhpWord\Writer\Word2007\Part\Document` for direct writer access. Rejected — internal API, no stable contract, regressions per PhpWord minor version.

### D2. Per-format strategy pattern

```php
interface SanitizerInterface {
    public function supports(string $mimeType): bool;
    public function sanitize(string $sourcePath, string $destPath): SanitizationReport;
}

class DocxSanitizer implements SanitizerInterface { ... }
class OdtSanitizer implements SanitizerInterface { ... }

class OfficeDocumentSanitizer {
    /** @var SanitizerInterface[] */ private array $strategies;

    public function sanitize(int $fileId): SanitizationResult; // (path, report)
}
```

The orchestrator resolves the input file via NC's `IRootFolder`, MIME-sniffs it, picks the strategy, runs it on a temp copy. Returns a tuple of (sanitised path, report).

**Rationale:** keeping DOCX and ODT logic in separate classes avoids tangled conditionals. Adding PPTX / ODS later (out of scope here) means a new strategy class without touching existing ones.

### D3. Tracked-changes resolution: accept all inserts, drop all deletions

In `<w:ins>...</w:ins>`: unwrap — keep the inner runs (insertion accepted). In `<w:del>...</w:del>`: remove the whole element (deletion accepted; deleted text gone). Revision attributes (`w:rsidR`, `w:rsidRPr`, `w:rsidDel`) on runs / paragraphs / cells are stripped via attribute removal in a final XPath pass.

For ODT: `text:tracked-changes` container removed. Inline `text:change-start` and `text:change-end` markers paired with their `text:change-id`: looked up in the now-removed container to determine accept vs reject. Without the container, assume "accept all visible content" — the `text:change-end` markers and their inline content are kept, `text:change` (delete markers in-text) are removed along with their referenced ranges.

**Rationale:** "accept all changes" matches the user's intent ("only keep the definitive text"). Accepting deletions (keeping the deleted text) is the opposite of what an operator anonymising the document wants.

**Trade-off:** if a tracked-change deletion was a valid edit (an author removed a draft sentence intentionally), accepting it as "drop" matches Word's "Accept All Changes" command — the standard Office-tool semantics. Operators familiar with Word will not be surprised.

### D4. Custom XML parts: strip all

Every part path matching `customXml/item*.xml` and `customXml/itemProps*.xml` is removed from the ZIP. Corresponding entries in `[Content_Types].xml` and `_rels/*.rels` are removed. In `word/document.xml`, any `<w:sdt>` element whose `<w:sdtPr><w:dataBinding ...>` references one of these parts: the `<w:sdt>` wrapper is replaced by its `<w:sdtContent>` inner content (preserving the visible text that was bound).

**Rationale:** custom XML carries CRM-templated data with PII not visible in the document body. Stripping preserves the visible (already PII-bearing in body text, walker-anonymisable) while removing the hidden duplicate.

**Trade-off:** content controls bound to data sources lose their binding. After sanitisation, re-saving the document in Word will not re-populate the controls from data. Acceptable: the anonymised derivative is meant to be a frozen artefact, not a re-editable template.

### D5. Document metadata: sentinel replacement, not deletion

For each metadata field listed in proposal § "What Changes" (`dc:creator`, `cp:lastModifiedBy`, `dc:title`, `dc:subject`, `cp:keywords`, `dc:description`, `cp:category`, `cp:contentStatus`, `Company`, `Manager`), the element's text content is replaced with the string **`DocuDesk Anonymisation`**. The element is preserved structurally — Word, LibreOffice, and downstream tools see a well-formed `<dc:creator>DocuDesk Anonymisation</dc:creator>` rather than an absent tag.

Fields not in the strip list (e.g. `dcterms:created`, `dcterms:modified` timestamps) are preserved as-is; they don't carry PII.

Custom doc properties under `docProps/custom.xml`: every `<property>` is preserved structurally but its inner `<vt:lpwstr>` / `<vt:lpstr>` value is replaced with the sentinel. Type-coercion concerns (a `<vt:i4>` integer property being replaced with a string) are avoided by skipping non-string-typed custom properties — they remain untouched.

**Rationale (sentinel over empty):** preserving the element with a sentinel value:
1. Tells anyone opening the document the doc was processed (audit trail in-file).
2. Defends against Word's "fill missing metadata on save" behaviour, which would otherwise re-populate `<dc:creator>` with the current logged-in user on next save — re-leaking PII.
3. Survives downstream tools that fail on missing-element vs empty-string mismatches.

**Trade-off:** the sentinel is a tool brand ("DocuDesk Anonymisation"). Some legal teams prefer a generic "Anonymous" or "Redacted". The sentinel string is a single config value (constant on the sanitiser) and can be changed by a one-line patch later without touching the surgery logic.

### D6. Person-identity field codes: drop the whole field

Two OOXML forms exist:

```xml
<!-- Simple form -->
<w:fldSimple w:instr=" AUTHOR ">
    <w:r><w:t>Robert Zondervan</w:t></w:r>
</w:fldSimple>

<!-- Complex form -->
<w:r><w:fldChar w:fldCharType="begin"/></w:r>
<w:r><w:instrText> USERNAME </w:instrText></w:r>
<w:r><w:fldChar w:fldCharType="separate"/></w:r>
<w:r><w:t>Robert Zondervan</w:t></w:r>
<w:r><w:fldChar w:fldCharType="end"/></w:r>
```

Sanitiser matches both forms. The instruction is normalised (case-insensitive, whitespace-stripped) and compared against the strip list: `AUTHOR`, `USERNAME`, `USERINITIALS`, `LASTSAVEDBY`. On match, the whole field (wrapper + cached result inner runs) is removed.

Other fields (`DATE`, `TIME`, `PAGE`, `NUMPAGES`, `FILENAME`, `TITLE`, etc.) are preserved. `FILENAME` may leak PII if the file is named `Verklaring-Jan-Jansen.docx`, but renaming is the operator's responsibility — sanitising the document content is the scope of this change.

For ODT: equivalent inline placeholders `text:author-name`, `text:author-initials`, `text:initial-creator` are removed in their entirety (no inner content needed — these have no separately-cached result, they're rendered live by the viewer).

**Rationale:** dropping cached + source together prevents the walker from later encountering a bare "Robert Zondervan" text run with no field context (which would have to be detected as a PERSON entity and substituted — extra work the sanitiser obviates).

### D7. Hyperlinks: flatten, drop URL + relationship

For each `<w:hyperlink>` element in `document.xml` / headers / footers / footnotes:

1. Note the `r:id` attribute.
2. Replace the `<w:hyperlink>` element with its inner `<w:r>` runs (text content kept).
3. In `_rels/document.xml.rels` (or the rels file paired with the part), remove the `<Relationship>` element with the matched `Id`.

For ODT: `<text:a xlink:href="...">inner content</text:a>` is replaced with the inner content (effectively becoming a `<text:span>`). No relationship file equivalent — the URL was inline.

Result: the visible link text remains and will be walker-anonymised in the entity pass. The URL is gone — no `mailto:p.jansen@…` survives.

**Edge case:** internal-document anchors (`#bookmark1`, `_Toc12345`). These have no PII and arguably should be preserved as live links. v1 strips them anyway — internal anchors are uncommon in Woo/dossier correspondence. Future refinement can preserve internal anchors if a real workflow needs them.

### D8. Original preservation via temp-file pipeline

```php
$temp = $this->tempManager->getTemporaryFile('.docx'); // NC's ITempManager
copy($sourceNCFile->getStoragePath(), $temp);
$report = $strategy->sanitize($temp, $temp); // in-place on the temp copy
return new SanitizationResult(path: $temp, report: $report);
```

`OfficeDocumentSanitizer::sanitize($fileId)` returns the path AND the report. Caller is responsible for moving / uploading the sanitised output to its destination, and for cleaning up the temp file when done (NC's `ITempManager` auto-cleans at request end as a safety net).

**Rationale:** decouples the sanitiser from the destination convention. `DocumentProcessingHandler` calls it, then runs the walker on the temp file, then writes the final anonymised output to the dossier folder per existing flow. The temp file is the working canvas.

### D9. Sanitisation report shape and persistence

```php
final class SanitizationReport implements \JsonSerializable {
    public readonly int $commentsRemoved;
    public readonly int $trackedChangesAccepted;
    public readonly int $trackedChangesDropped;
    public readonly int $revisionAttributesStripped;
    public readonly int $hyperlinksFlattened;
    public readonly int $metadataFieldsScrubbed;
    public readonly int $customXmlPartsDropped;
    public readonly int $fieldCodesStripped;
    public readonly string $sentinelApplied;

    public function jsonSerialize(): array { ... }
}
```

Persisted on `AnonymizationLog.sanitization` (new JSON column). DocuDesk reads this from the log row via the existing anonymisation-log API. Format: JSON object with the above keys; tooling can extend with new keys without migration (JSON column, additive).

### D10. Validation gate: Word + LibreOffice reopen test

Before any DOCX-sanitiser task is marked done, the implementer MUST:

1. Take a sample `.docx` that contains comments, tracked changes, custom XML, hyperlinks, person-identity fields, and rich metadata.
2. Run it through `DocxSanitizer`.
3. Open the sanitised output in **Microsoft Word** (current LTSC or 365 channel).
4. Open the sanitised output in **LibreOffice** (24.x or newer).
5. **Required outcome:** both open without any "found unreadable content / want to recover?" warning. The visible content matches what was visible before sanitisation, minus stripped structures.

Same validation gate for `.odt` with Word and LibreOffice — Word can open ODT and is the more sensitive reader.

If the gate fails:
- "Repair" warning = orphan reference somewhere. Diff `[Content_Types].xml`, `_rels/*.rels`, and the document body to find the dangling ref. Fix the surgery to update the matching index/rels file.
- Visible content corruption = surgery went too deep. Probably an `<w:sdt>` wrapper that should have kept its `<w:sdtContent>`, or a hyperlink whose inner runs were dropped instead of preserved.

Sample fixture in `tests/Fixtures/sanitization/` for repeatable validation.

### D11. Logging follows ADR-005 (no PII)

Per ADR-005, sanitiser logs may NOT contain comment text, tracked-change text, metadata values, custom XML content, hyperlink URLs, or any other field that could carry PII. Permitted log content: file ID, MIME type, counts (per `SanitizationReport`), strategy class name, structural error detail (e.g. "missing [Content_Types].xml entry for word/comments.xml").

`SanitizationException` messages are sanitised before logging — they MAY reference part paths and OOXML / ODT element names but MUST NOT include content.

## Risks / Trade-offs

- **[Word "found unreadable content" warning after sanitisation]** → Mitigation per D10: validation gate against Word + LibreOffice with rich fixture; any orphan reference fixed before task close.
- **[Custom XML data bindings break]** → Acceptable per D4; the anonymised derivative is a frozen artefact, not a re-editable template. Documented in CHANGELOG and proposal § "Out of scope".
- **[Sentinel string brand "DocuDesk Anonymisation"]** → Single constant; legal teams can patch to "Anonymous" or any string. Surfaced in D5 as a known soft preference.
- **[Performance on large documents (>10 MB body)]** → DOMDocument loads the whole XML into memory; for 50MB+ documents this is memory-pressured. Acceptable: real-world Woo docs are well under 10 MB. If 100MB+ workloads emerge, follow-up to streaming XMLReader/XMLWriter approach (substantially more complex; defer until needed).
- **[Encrypted DOCX / ODT]** → Per D2 (orchestrator), `ZipArchive::open` fails on encrypted documents (returns an error code); orchestrator translates this into `SanitizationException("encrypted document — cannot sanitize")` and the caller decides UI behaviour (e.g. DocuDesk shows "Cannot anonymise an encrypted document").
- **[Word reading sanitised file silently downgrades feature support]** → Word generally tolerates missing optional parts gracefully. If a downgrade is observed (e.g. "compatibility mode" badge), document in CHANGELOG as expected behaviour.
- **[Internal-anchor hyperlinks stripped]** → Per D7 edge case. Documented; future refinement if needed.
- **[Operator surprise — anonymised doc no longer has reviewer comments]** → DocuDesk surfaces the sanitisation report counts in the per-file anonymisation summary. Operator sees "12 comments removed" in the UI; informational, not a confirmation gate (since the sanitisation is non-destructive — the original is preserved).
- **[ODT-side incomplete revision history]** → ODT's `text:tracked-changes` is the canonical store of insert/delete metadata. Removing it before resolving accept-vs-reject means insert markers become unresolvable. Mitigation: parse the container BEFORE removal, walk inline `text:change-end` and `text:change-start` markers using the parsed metadata, THEN remove the container.

## Migration Plan

1. Add the `sanitization` JSON column to `AnonymizationLog` (one migration; nullable; default null).
2. Land the `SanitizerInterface`, `DocxSanitizer`, `OdtSanitizer`, `OfficeDocumentSanitizer`, `SanitizationReport`, `SanitizationException` classes.
3. Wire `OfficeDocumentSanitizer` into `DocumentProcessingHandler::anonymizeDocument` — sanitise before walker pass for DOCX / ODT.
4. Persist `SanitizationReport` JSON onto the anonymisation log row.
5. Add the test fixtures and the validation gate per D10.
6. Release. Existing anonymisation-log rows have `sanitization: null` (interpreted by DocuDesk's report renderer as "pre-sanitisation run; no data"). New anonymisations carry the populated report.

**Rollback:** revert the `DocumentProcessingHandler` wiring change. Sanitiser classes become unused. JSON column on `AnonymizationLog` is benign (nullable, no required writes). Revert is per-commit clean.

## Seed Data

Not applicable — this change adds a service and a parser, not new OpenRegister schemas. No `_registers.json` entries required.

## Open Questions

- **Sentinel string choice (`DocuDesk Anonymisation` vs `Anonymous` vs configurable)** — provisional `DocuDesk Anonymisation` per Decisions D5. If a legal review wants generic, swap the constant. Configurable per-tenant is a follow-up only if multiple tenants want different sentinels.
- **Should `text:user-defined` fields under ODT `meta.xml` be scrubbed individually or all dropped?** — provisional: scrub individually (replace each value with sentinel). All-drop would simplify but risks downstream tools that expect specific custom fields to exist.
- **Hyperlink internal anchors (`#bookmark1`)** — provisional: strip (per D7 edge case). If operators need internal navigation preserved in anonymised derivatives, follow-up.
- **PhpWord-DOC (legacy binary) handling** — provisional: legacy `.doc` is read-only via PhpWord-DOC reader, NOT sanitised. Confirm at apply time whether the existing pipeline accepts `.doc` for anonymisation at all (it may already 422 on unsupported input).
- **Audit-log visibility in DocuDesk UI** — provisional: DocuDesk renders sanitisation counts as a Twig block in the grondslagen summary report. UX detail is DocuDesk-side, not specified here.
