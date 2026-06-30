---
kind: code
depends_on: [text-extraction-eml]
---

## Why

OpenRegister can already EXTRACT and structurally PARSE EML (`message/rfc822`) via `TextExtractionService::parseEmlStructured()` and the `EmlStructure` / `EmlBody` / `EmlAttachment` value objects shipped by the `text-extraction-eml` change. But it CANNOT redact EML safely. When an operator anonymises a `.eml` file, `DocumentProcessingHandler::replaceWords()` routes it through neither the Word nor the PDF redactor; it falls through to the ELSE branch (`replaceWordsInTextDocument()`), which runs `str_ireplace` over the **raw transport bytes** of the file. An `.eml` body part is almost always base64- or quoted-printable-encoded, and every attachment is encoded too — so the entity text the operator wants redacted **never appears literally in those bytes**. The substitution silently does nothing for those parts, and OR emits a file that still contains the PII in its encoded body and attachments. This is a privacy defect: a Woo/Wob email dossier "anonymised" through OR today still leaks names, addresses, and attachment contents.

This change adds an **EML-aware anonymisation path** so redaction operates on DECODED content, and exposes the redacted result as a structured cross-app contract for DocuDesk's `eml-pdf-assembly` consumer to assemble into a PDF.

**depends_on:** `text-extraction-eml` (in this same repo). That change is the foundation — it provides `parseEmlStructured()` and the `EmlStructure` / `EmlBody` / `EmlAttachment` value objects this change reads. This change MUST NOT redesign those; it consumes their contract. The dependency is narrated here rather than tracked by an issue number.

## What Changes

- **Route `message/rfc822` away from the raw-text fallback.** `DocumentProcessingHandler::anonymizeDocument` (and the `replaceWords` dispatch it calls) gains an EML branch: an EML input is parsed via `parseEmlStructured()` and redacted on its DECODED content, never on its transport bytes.
- **NEW: decoded body redaction.** Both the `text/plain` and `text/html` body parts are redacted using the SAME entity→placeholder replacement map the existing redactors build (stable `[<TYPE>: <number>]` placeholders, scope-local numbering via `PlaceholderIdTranslator`).
- **NEW: display-header redaction.** Header values rendered to the consumer (From / To / Cc / Reply-To / Subject / Date and any other display headers) are redacted with the same map, since they carry PII (names, addresses).
- **NEW: per-attachment redaction inside OR.** For each `EmlAttachment`, OR materialises the decoded `content` bytes and runs the matching EXISTING per-format redactor — docx/odt/rtf/html via the Word path, pdf via the PDF path, plain text via the text path, and nested `message/rfc822` by recursing this same EML path (honouring `EmlParser::MAX_DEPTH` = 3). The redacted bytes are returned in the result.
- **NEW: unsupported-attachment policy ("flag, drop content").** Attachments whose MIME type has no redactor (xlsx, ods, archives, calendar, images, anything unhandled) are NEVER emitted with content. They are marked `unsupported: true` (filename + mimeType + flag) so the consumer can render a placeholder page noting the attachment was omitted because no anonymiser was available.
- **NEW: `AnonymisedEmlStructure` value object** — the redacted structured result: redacted display `headers`, redacted `body` (`plain` and/or `html`, may be null), `attachments[]` each = `{filename, mimeType, redactedContent | unsupported:true}`, and an inline-image map (`contentId → redacted bytes`) for `cid:` resolution. This is the CROSS-APP CONTRACT consumed by DocuDesk's `eml-pdf-assembly`.
- **NEW: public entry** `anonymizeEmlStructured()` on FileService (delegating to `DocumentProcessingHandler`), returning `AnonymisedEmlStructure`, alongside the existing `anonymizeDocument()`. EML inputs to `anonymizeDocument()` route into the same EML logic.
- **Reuse, no new detection.** The entity-detection / replacement-map building and the `scope` / `dossierKey` placeholder-numbering semantics already in `anonymizeDocument` are reused unchanged; placeholder numbering is consistent across the email body, headers, and all attachments.
- **Output ownership.** OR produces ONLY the redacted structured result (and/or a redacted intermediate). OR does NOT render PDF — "EML always outputs PDF" is enforced on the DocuDesk side. OR's contribution is purely redaction.
- **NO new HTTP endpoint, NO DB/schema change, NO new OpenRegister register/schema/object** (so NO seed data). NO new external dependency (`zbateson/mail-mime-parser` already present from `text-extraction-eml`).

## Capabilities

### New Capabilities

- `eml-anonymisation`: EML-aware anonymisation in `DocumentProcessingHandler` — decoded body + display-header redaction, per-attachment redaction via the existing per-format redactors on materialised bytes, nested-EML recursion at depth 3, the unsupported-attachment "flag, drop content" policy, and the `AnonymisedEmlStructure` cross-app result contract.

### Modified Capabilities

(none — the broader `DocumentProcessingHandler` / `anonymizeDocument` surface is currently covered only by the per-format capabilities `pdf-anonymisation` and `entity-relation-grondslagen`; neither defines the EML dispatch behaviour. The EML branch is new and additive, so it is captured as a new capability rather than a delta to an existing one. The non-EML dispatch branches are unchanged.)

## Impact

- **Code (openregister):**
  - `lib/Service/File/DocumentProcessingHandler.php` — add the EML branch to the dispatch; add the EML redaction orchestration (body + headers + attachment loop + nested recursion + unsupported flagging); reuse the existing replacement-map builder and `PlaceholderIdTranslator`.
  - `lib/Service/File/AnonymisedEmlStructure.php` (NEW) — the redacted structured result value object (cross-app contract).
  - `lib/Service/File/AnonymisedEmlAttachment.php` (NEW) — per-attachment result entry (`redactedContent | unsupported`).
  - `lib/Service/FileService.php` — add `anonymizeEmlStructured()` delegating to the handler; route EML inputs of `anonymizeDocument()` into the EML path.
  - Reuses `lib/Service/TextExtractionService.php::parseEmlStructured()` and `lib/Service/TextExtraction/EmlParser.php` (no change to those).
- **API contract:** No HTTP surface change. New service-level method consumed via DI.
- **Cross-app:** DocuDesk's `eml-pdf-assembly` consumes `AnonymisedEmlStructure` (defined crisply in `contract.md`). DocuDesk owns the PDF rendering and the "EML always outputs PDF" rule. opencatalogi / softwarecatalog are unaffected.
- **Privacy / compliance:** Closes the base64/quoted-printable leak — the central reason for this change. Unsupported attachments are dropped rather than emitted unredacted.
- **Tests:** Unit tests for every new path (body redaction both parts, header redaction, each attachment redactor route, nested EML recursion + depth cap, unsupported flagging, inline-image map, placeholder-numbering consistency across body + attachments).
- **Migration:** None. No DB change. EML inputs that today produce a leaky output now produce a redacted structured result; documented under CHANGELOG "Behavior changes".