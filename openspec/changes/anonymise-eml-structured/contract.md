# Cross-app contract: `AnonymisedEmlStructure`

This is the redaction-output contract OpenRegister exposes for DocuDesk's `eml-pdf-assembly` consumer. OpenRegister produces it; DocuDesk consumes it to assemble the PDF. OpenRegister does NOT render PDF — "EML always outputs PDF" is enforced on the DocuDesk side.

## Producer

- **App:** OpenRegister (`ConductionNL/openregister`).
- **Entry point:** `FileService::anonymizeEmlStructured(\OCP\Files\Node $node, array $entities, string $scope = 'document', ?string $dossierKey = null): AnonymisedEmlStructure` (delegates to `DocumentProcessingHandler`).
- Acquired by the consumer via Nextcloud DI / the OpenRegister integration registry, the same way `parseEmlStructured()` is consumed today.

## Shape

```php
final class AnonymisedEmlStructure implements \JsonSerializable {
    public readonly array $headers;                       // display subset, redacted (see below)
    public readonly AnonymisedEmlBody $body;              // redacted body (plain and/or html, each nullable)
    /** @var array<int, AnonymisedEmlAttachment> */
    public readonly array $attachments;                   // multipart order preserved
    /** @var array<string, string> contentId => decoded redacted bytes */
    public readonly array $inlineImages;                  // for cid: resolution in the HTML body
}

final class AnonymisedEmlBody implements \JsonSerializable {
    public readonly ?string $plain;   // redacted text/plain, or null when absent
    public readonly ?string $html;    // redacted text/html (markup preserved), or null when absent
}

final class AnonymisedEmlAttachment implements \JsonSerializable {
    public readonly string $filename;
    public readonly string $mimeType;
    public readonly ?string $redactedContent;   // decoded redacted bytes; null when unsupported
    public readonly bool $unsupported;          // true => content omitted, render placeholder page
}
```

### `headers` (display subset)

Associative array of redacted display header values. At minimum: `from` (string), `to` (string[]), `cc` (string[]), `replyTo` (string), `subject` (string), `date` (string). Implementations MAY add further display headers. All values are post-redaction (PII replaced by `[<TYPE>: <number>]` placeholders).

### Placeholder format

Redacted text (body, headers, and the text inside redacted attachments) uses the existing stable placeholder `[<TYPE>: <number>]`, where `<number>` is scope-local (per `scope` / `dossierKey`) and `<TYPE>` is the localised entity-type label. The SAME entity yields the SAME placeholder across the body, headers, and every attachment of one message — so the consumer's grondslagen cross-reference holds.

### `attachments[]`

In multipart-document order.

- **Supported:** `unsupported = false`, `redactedContent` = decoded redacted bytes. The consumer embeds/renders these (PDF/A-3 attachment or inline render).
- **Unsupported:** `unsupported = true`, `redactedContent = null`. No anonymiser exists for this MIME (xlsx, ods, archives, calendar, images, octet-stream, nested EML beyond depth 3). The consumer renders a placeholder page noting the attachment was omitted. The consumer MUST NEVER expect raw, unredacted bytes here — OpenRegister never emits them.

### `inlineImages`

`contentId → decoded redacted bytes`. Lets the consumer resolve `<img src="cid:...">` references in the redacted HTML body. Inline images that fall under the unsupported policy are absent from this map.

## JSON serialisation

`jsonSerialize()` base64-encodes all byte fields (`redactedContent`, `inlineImages` values) — same convention as `EmlAttachment` from `text-extraction-eml` — so the JSON form is safe to print. Consumers using the PHP value object directly receive raw bytes via the typed properties and MUST NOT base64-decode those again.

## Stability / versioning

This contract is additive over `text-extraction-eml`'s `EmlStructure` (the redacted, OR-output-side counterpart). New optional fields may be added without breaking consumers; removing or retyping a field is a breaking change requiring a coordinated DocuDesk update.