## Context

OpenRegister's anonymisation engine lives in `DocumentProcessingHandler`. `anonymizeDocument(Node, entities[], scope, dossierKey)` builds a `needle → [<TYPE>: <number>]` replacement map (reusing stable per-entity ids from `EntityRelationMapper` and a scope-local `PlaceholderIdTranslator`), then calls `replaceWords()`, which dispatches by file extension:

- `doc` / `docx` → `replaceWordsInWordDocument()` (PhpWord, recurses sections/headers/footers/tables/lists).
- `pdf` → `replaceWordsInPdfDocument()` (SAPP byte-level pipeline + metadata sanitiser, with a residual gate).
- ELSE → `replaceWordsInTextDocument()` (`str_ireplace` over `$node->getContent()`).

An `.eml` (`message/rfc822`) has no Word/PDF branch, so it hits the ELSE branch and gets `str_ireplace` over its **raw transport bytes**.

**The base64-leak rationale.** An RFC 822 message is a structured container. Header values may be RFC 2047 encoded-words (`=?utf-8?B?…?=`). Body parts are almost always transfer-encoded — `Content-Transfer-Encoding: base64` or `quoted-printable`. Every attachment is base64. So the entity text the operator wants to redact (e.g. `Jan de Vries`, `06-12345678`) is NOT present as literal bytes anywhere in the encoded body or attachments — it only exists after decoding. `str_ireplace` over the transport bytes therefore matches nothing in those parts and silently leaves the PII intact, while OR reports a "successful" anonymise and writes a file that still leaks. This is identical in spirit to the PDF defect the `pdf-anonymisation` change fixed (binary container, encoded content streams), and the fix is the same shape: redact the DECODED content, not the transport bytes.

`text-extraction-eml` already gives us decoded content for free: `TextExtractionService::parseEmlStructured(File): EmlStructure` returns decoded `headers`, an `EmlBody` (`plainText` / `html`), and `EmlAttachment[]` whose `content` is decoded bytes (`isInline`, `contentId`, `nestedEml` populated, depth cap 3 via `EmlParser::MAX_DEPTH`). This change builds the redaction layer ON TOP of that parse — it does not re-parse MIME itself.

## Goals / Non-Goals

**Goals:**

- Route `message/rfc822` away from the raw-text fallback into an EML-aware redaction path that operates on DECODED content.
- Redact both body parts (`text/plain`, `text/html`) and the display headers (From / To / Cc / Reply-To / Subject / Date and other display headers) with the SAME replacement map the existing redactors use.
- Redact each attachment by materialising its decoded bytes and running the matching EXISTING per-format redactor; recurse into nested `message/rfc822` honouring the depth-3 cap.
- Drop (never emit) content for attachment formats no redactor handles, flagging them so the consumer renders a placeholder page.
- Expose the result as a crisp `AnonymisedEmlStructure` cross-app contract for DocuDesk's `eml-pdf-assembly`.
- Keep placeholder numbering consistent across the email body, headers, and all attachments of a single message.

**Non-Goals:**

- Rendering a PDF. Output ownership is DocuDesk's: "EML always outputs PDF" is enforced there. OR produces only the redacted structured result.
- Re-implementing MIME parsing, the value objects, or the depth limit — all owned by `text-extraction-eml`.
- New entity detection. The replacement map and scope/dossier numbering are reused unchanged.
- Decrypting S/MIME or PGP EML, handling `mbox`/`pst` containers, or extracting calendar bodies — same out-of-scope set as `text-extraction-eml`.
- Any new HTTP endpoint, DB column, register/schema/object, or external dependency.

## Decisions

### D1 — Redact decoded content, reusing the existing per-format redactors

The EML path parses via `parseEmlStructured()`, then:

- **Body parts:** apply the replacement map to `body->plainText` and `body->html` as strings (the same operation `replaceWordsInTextDocument` performs, but on decoded text rather than transport bytes). HTML is redacted as text content; tag/attribute structure is preserved so the consumer can still render it.
- **Display headers:** apply the map to the rendered header values (From / To / Cc / Reply-To / Subject / Date and any other display headers OR surfaces). These are already RFC 2047-decoded by the parser.
- **Attachments:** for each `EmlAttachment`, write the decoded `content` bytes to a transient in-memory/temp file node and invoke the EXISTING redactor for its MIME:
  - docx / odt / rtf / html → the Word path (`replaceWordsInWordDocument`-equivalent on the materialised bytes).
  - pdf → the PDF path (`replaceWordsInPdfDocument`-equivalent).
  - plain text → the text path.
  - `message/rfc822` → recurse the EML path (see D3).

Reusing the existing redactors is the whole point: the byte-level correctness, residual gating, and metadata sanitisation already solved for Word and PDF are inherited rather than re-derived. The redactors today take a `Node`; the attachment loop materialises bytes into a Node-like input (temp file in the user's scratch space or an in-memory wrapper) so the existing methods apply unchanged. *Alternative considered:* refactor each redactor to a `(bytes) → bytes` core. Cleaner long-term, but a larger blast radius touching the proven PDF/Word paths; deferred — the materialise-to-node adapter is the lower-risk v1.

### D2 — Unsupported attachments: flag, drop content (never emit unredacted)

If an attachment's MIME has no redactor (xlsx, ods, zip/archives, `text/calendar`, images, octet-stream, anything unhandled), OR MUST NOT include its content in the result. It is represented as `{filename, mimeType, unsupported: true}` with no `redactedContent`. The consumer renders a placeholder page ("attachment omitted — no anonymiser available"). This is the agreed policy: emitting an attachment's bytes unredacted is the exact leak this change exists to prevent, so "drop content" is fail-closed and correct. *Alternative considered:* a black-box/redact-everything fallback for unknown types — rejected: it cannot guarantee no-leak for arbitrary binary formats and gives false assurance.

### D3 — Nested EML recursion + depth limit

A `message/rfc822` attachment is redacted by recursing the EML path on its bytes. The recursion reuses the SAME depth accounting as `text-extraction-eml` (`EmlParser::MAX_DEPTH` = 3; root = depth 0). Beyond the cap, `parseEmlStructured` already returns the attachment shell with `nestedEml: null`; the anonymisation path treats a nested EML it cannot descend into as an **unsupported attachment** (D2) — flagged, content dropped — rather than emitting its raw bytes. This bounds worst-case work and prevents a malicious deep-nesting DoS, consistent with the parse-side cap.

### D4 — Placeholder-numbering consistency across body + attachments

The replacement map (and its scope-local `PlaceholderIdTranslator`) is built ONCE for the whole message and applied to the body, the headers, and every attachment redaction (including nested EML). This means `[PERSOON: 1]` denotes the same person whether it appears in the email body, the From header, or inside an attached PDF — which is what the grondslagen-summary cross-reference relies on. The `scope` (`document` / `dossier`) and `dossierKey` semantics already in `anonymizeDocument` carry through unchanged; under `dossier` scope the translator is seeded from the dossier folder exactly as today, so an EML and its sibling files in the same dossier share numbering.

### D5 — `AnonymisedEmlStructure` cross-app contract

The redacted result is a new immutable value object (mirroring the `EmlStructure` style, but post-redaction and OR-output-side, so it lives under `lib/Service/File/` next to the handler, not under `TextExtraction/`):

```php
AnonymisedEmlStructure {
    headers: [ 'from' => '<redacted display value>', 'to' => [...], 'cc' => [...], 'subject' => '...' , ... ],  // display subset, redacted
    body: AnonymisedEmlBody { plain: ?string, html: ?string },  // either/both may be null
    attachments: [
        AnonymisedEmlAttachment { filename, mimeType, redactedContent: <bytes> },          // supported
        AnonymisedEmlAttachment { filename, mimeType, unsupported: true },                  // dropped
    ],
    inlineImages: [ '<contentId>' => <redacted bytes>, ... ],   // cid: resolution for the HTML body
}
```

Defined crisply in the optional `contract.md` artifact (per the cross-app-API guidance) so DocuDesk's `eml-pdf-assembly` has a single source of truth. `jsonSerialize()` base64-encodes byte fields for safe transport (same convention as `EmlAttachment`). Consumers using the PHP value object receive raw bytes directly. *Alternative considered:* return a plain associative array — rejected; a typed object gives DocuDesk a stable contract and lets PHPStan enforce shape on both sides.

## Declarative-vs-imperative (ADR-031)

**Justified-imperative — N/A to the declarative trigger list.** ADR-031 governs schema-level business logic: lifecycle hooks, aggregations, derived fields, declarative relations, notifications, dashboard widgets configured as OpenRegister data. This change introduces NONE of those. It adds:

- MIME parsing and transfer-decoding (delegated to `text-extraction-eml`),
- per-format binary redaction (PDF byte pipeline, PhpWord, text replacement),
- recursion over nested message containers with a depth cap,
- a code-level value-object contract for a cross-app consumer.

These are document parsing / redaction / binary-handling concerns — inherently imperative service code, not a declarative lifecycle/aggregation/derived-field concern. No register/schema/object is created or modified, so **no seed data is needed and none must be generated by the apply agent.** ADR-031's declarative path does not apply.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| HTML body redaction could match entity text inside tag names/attributes and corrupt markup | Redact text content only; preserve tag/attribute structure. Covered by a unit test asserting tags survive. |
| Materialising attachment bytes to a temp node adds I/O and temp-file lifecycle | Use the user scratch space; clean up in a `finally`. Bounded by the same per-type extractor costs already accepted in `text-extraction-eml`. |
| Existing PDF redactor fails-closed (residual gate) on a problem attachment | The attachment-level failure is surfaced per-attachment (the message still produces a result for the other parts); the failing attachment is reported, not silently emitted unredacted. Matches the best-effort residual policy of the per-format redactors. |
| A nested EML beyond depth 3 carries `nestedEml: null` | Treated as an unsupported attachment (D3) — flagged, content dropped — never emitted raw. |
| PII in logs (ADR-005) — headers/bodies/filenames are PII | Reuse the parser's PII-sanitised logging; log only structural detail (file id, MIME, attachment index, redactor class, depth). No header/body/filename values logged. |

## Migration Plan

No DB migration. Deploy is code-only. Behavioural change for `message/rfc822` inputs: they were silently leaking; they now produce a redacted `AnonymisedEmlStructure`. Document under CHANGELOG "Behavior changes". Rollback is reverting the code; no data migration to undo. DocuDesk's `eml-pdf-assembly` is the paired consumer — it ships behind its own change and tolerates OR not yet exposing the method (its existing flat-text fallback).

## Open Questions

See the DEFERRED_QUESTIONS returned with this draft (header display subset exact membership; attachment-redactor-failure surfacing granularity; AnonymisedEmlBody as a sub-object vs. inline fields).