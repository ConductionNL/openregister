# EML Text Extraction

OpenRegister's `TextExtractionService` supports EML files (`message/rfc822`) alongside PDF, Word, and spreadsheet formats. Two extraction paths share one underlying parser.

## Flat plain-text extraction

The existing `extractFile($fileId)` workflow now handles EML inputs. The output is a single UTF-8 string in the following order:

```
From: Alice Bakker <alice@example.com>
To: Bob de Vries <bob@example.com>; Carol Jansen <carol@example.com>
Cc: Dave Mulder <dave@example.com>
Subject: Beantwoording Woo-verzoek 2025-017
Date: 2026-04-12T11:00:00+0000

Geachte raadslid,

(... body text ...)

--- Attachment: bijlage-1.pdf ---
(... extracted text from the PDF attachment ...)

--- Attachment: image.png (image/png, not extractable) ---
```

Headers are decoded from RFC 2047 encoded-word form. The body prefers `text/plain`; if only `text/html` is present, OpenRegister strips tags and decodes entities. Non-extractable attachments (images, binary blobs, calendar invites) appear as a single marker line with no body.

## Structured parse

The `parseEmlStructured(\OCP\Files\File $file): EmlStructure` method returns a rich value object:

```php
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Exception\EmlParseException;

$textExtraction = $container->get(TextExtractionService::class);

try {
    $structure = $textExtraction->parseEmlStructured($ncFile);

    // Headers
    $from    = $structure->headers['from'];        // "Alice Bakker <alice@example.com>"
    $to      = $structure->headers['to'];          // string[] — always an array
    $subject = $structure->headers['subject'];
    $date    = $structure->headers['date'];        // DateTimeImmutable|null

    // Body — consumer picks the part they need
    $plainText = $structure->body->plainText;      // string|null
    $html      = $structure->body->html;           // string|null

    // Attachments — in multipart order
    foreach ($structure->attachments as $attachment) {
        $attachment->filename;    // never empty
        $attachment->mimeType;
        $attachment->content;     // decoded bytes — ready to embed in PDF or data: URI
        $attachment->isInline;
        $attachment->contentId;   // for cid: HTML references
        $attachment->nestedEml;   // EmlStructure|null — populated for forwarded EMLs
    }
} catch (EmlParseException $e) {
    // Log and handle — $e->getMessage() is PII-safe
}
```

## Implementation details

| | |
|---|---|
| **Library** | `zbateson/mail-mime-parser` — pure PHP, no PECL extension required |
| **Recursion limit** | Nested `message/rfc822` attachments are parsed up to depth 3 (root = 0). Beyond that, the attachment is listed without a `nestedEml`. |
| **PII in logs** | Per ADR-005, logs never contain email addresses, display names, Subject content, body content, or attachment filenames. Only file ID, MIME type, exception class name, and structural details are logged. |
| **Encoding** | Non-UTF-8 body parts are transcoded on a best-effort basis via `mb_detect_encoding` + `mb_convert_encoding`. |
| **HTML stripping** | `<style>` and `<script>` blocks are removed; block-level elements are converted to newlines; tags are stripped; entities are decoded. |

## DocuDesk integration

DocuDesk's `eml-pdf-assembly` change uses `parseEmlStructured()` to render EML files as PDF/A-3b documents. It prefers the `html` body for visual fidelity, falling back to `plainText` wrapped in `<pre>`. Attachment bytes are embedded directly as PDF/A-3 file attachments via the decoded `content` field (no further base64 decoding needed).

## Changelog

- **Added in v0.3** — EML support via `zbateson/mail-mime-parser` ([#32](https://codeberg.org/Conduction/openregister/issues/32)).
