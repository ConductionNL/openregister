<?php

/**
 * EML (`message/rfc822`) parser.
 *
 * Two paths share an underlying `zbateson/mail-mime-parser` invocation:
 *
 *   - `parse(File $file): EmlStructure` — the structured-parse path
 *     used by `TextExtractionService::parseEmlStructured()` and (via
 *     DI lookup) by DocuDesk's `eml-pdf-assembly` for rich PDF/A-3
 *     rendering. MUST throw `EmlParseException` on irrecoverable
 *     malformed input; consumers rely on exception propagation for
 *     their fallback paths.
 *
 *   - `flatten(EmlStructure $structure, int $depth = 0): string` —
 *     the flat plain-text path used by `extractEml` for the entity
 *     detection / text extraction pipeline. Format: header block
 *     (From / To / Cc / Subject / Date), blank line, body
 *     (`text/plain` if present, otherwise HTML stripped to text),
 *     attachments listed under marker lines. Nested EML attachments
 *     are inlined via recursive `flatten()`.
 *
 * **v1 scope note (per the change's design doc):** non-EML extractable
 * attachments (PDF, DOCX, text, …) are listed by name + MIME type
 * only; inline-text extraction for those types requires a
 * `TextExtractionService::extractFromBytes` primitive that does not
 * yet exist. Tracked as a follow-up. The DocuDesk-side
 * `eml-pdf-assembly` consumer handles rich attachment rendering
 * separately and does not depend on flat-path inlining.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\EmlParseException;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Parser for `message/rfc822` files.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) MIME parsing requires several collaborating types
 */
class EmlParser
{
    /**
     * Maximum recursion depth for nested `message/rfc822` attachments.
     *
     * Root parse is depth 0; the limit of 3 permits parses at depths
     * 0, 1, 2, 3. Any deeper `message/rfc822` attachment is exposed
     * via its `EmlAttachment` shell with `nestedEml = null`.
     *
     * @var int
     */
    public const MAX_DEPTH = 3;

    /**
     * Underlying mime parser, lazily constructed.
     *
     * @var MailMimeParser|null
     */
    private ?MailMimeParser $parser = null;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Structured log sink (PII-sanitised — see
     *                                `sanitisePiiForLogging`).
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Parse an EML file into a structured `EmlStructure`.
     *
     * @param File $file  The Nextcloud File node to parse.
     * @param int  $depth Current recursion depth (root = 0).
     *
     * @return EmlStructure The parsed structure.
     *
     * @throws EmlParseException When the input is irrecoverably malformed.
     */
    public function parse(File $file, int $depth=0): EmlStructure
    {
        try {
            $stream = $file->fopen(mode: 'r');
            if ($stream === false) {
                throw new EmlParseException(message: 'Failed to open EML file stream');
            }

            $message = $this->getParser()->parse(resource: $stream, attached: true);
        } catch (EmlParseException $emlError) {
            throw $emlError;
        } catch (Throwable $error) {
            // Sanitise — the underlying parser may embed header fragments
            // in its message; we never log raw bytes.
            throw new EmlParseException(
                message: 'EML parse failure ('.get_class($error).')'
            );
        }//end try

        return $this->parseMessage(message: $message, depth: $depth);
    }//end parse()

    /**
     * Parse an in-memory IMessage into `EmlStructure`.
     *
     * Used internally for recursive nested-EML extraction; also reusable
     * by tests that construct a message in memory.
     *
     * @param IMessage $message The parsed message.
     * @param int      $depth   Current recursion depth.
     *
     * @return EmlStructure
     */
    public function parseMessage(IMessage $message, int $depth=0): EmlStructure
    {
        $headers     = $this->extractHeaders(message: $message);
        $body        = $this->extractBody(message: $message);
        $attachments = $this->extractAttachments(message: $message, depth: $depth);

        return new EmlStructure(
            headers: $headers,
            body: $body,
            attachments: $attachments
        );
    }//end parseMessage()

    /**
     * Build the flat plain-text representation of an EmlStructure.
     *
     * Order: header block, blank line, body (plainText preferred),
     * attachments under marker lines (recursively for nested EML).
     *
     * @param EmlStructure $structure Parsed structure.
     * @param int          $depth     Current recursion depth.
     *
     * @return string Flat plain-text output.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Mostly attachment-loop branching
     */
    public function flatten(EmlStructure $structure, int $depth=0): string
    {
        $lines = [];

        $headerOrder = ['from', 'to', 'cc', 'subject', 'date'];
        foreach ($headerOrder as $key) {
            $value = $structure->headers[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($key === 'date' && $value instanceof DateTimeImmutable) {
                $rendered = $value->format(DateTimeImmutable::ATOM);
            } else if (is_array($value) === true) {
                $rendered = implode(', ', $value);
            } else {
                $rendered = (string) $value;
            }

            $lines[] = ucfirst($key).': '.$rendered;
        }

        $lines[] = '';

        // Multipart/alternative preference: plainText > html-stripped fallback.
        // The spec is explicit — when both parts exist, MUST emit plain only
        // (do NOT concatenate the HTML).
        $bodyText = '';
        if ($structure->body->plainText !== null && $structure->body->plainText !== '') {
            $bodyText = $this->ensureUtf8(value: $structure->body->plainText);
        } else if ($structure->body->html !== null && $structure->body->html !== '') {
            $bodyText = $this->htmlToText(html: $this->ensureUtf8(value: $structure->body->html));
        }

        if ($bodyText !== '') {
            $lines[] = $bodyText;
            $lines[] = '';
        }

        foreach ($structure->attachments as $attachment) {
            if ($attachment->nestedEml !== null) {
                $lines[] = '--- Attachment: '.$attachment->filename.' ---';
                $lines[] = $this->flatten(structure: $attachment->nestedEml, depth: ($depth + 1));
                $lines[] = '';
                continue;
            }

            // Non-EML extractable types — inline text extraction is deferred
            // (see class docblock). For v1 we list the attachment by name + MIME.
            $marker  = '--- Attachment: '.$attachment->filename.' ('.$attachment->mimeType.', not extractable inline in v1) ---';
            $lines[] = $marker;
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines), "\n");
    }//end flatten()

    /**
     * Extract canonical headers from the message.
     *
     * Decodes RFC 2047 encoded-words via zbateson's built-in header parsing.
     *
     * @param IMessage $message Parsed message.
     *
     * @return array<string, mixed>
     */
    private function extractHeaders(IMessage $message): array
    {
        $rawDate = $message->getHeaderValue(name: 'Date');

        return [
            'from'      => $message->getHeaderValue(name: 'From'),
            'to'        => $this->splitAddressList(raw: $message->getHeaderValue(name: 'To')),
            'cc'        => $this->splitAddressList(raw: $message->getHeaderValue(name: 'Cc')),
            'subject'   => $message->getSubject(),
            'date'      => $this->parseDate(raw: $rawDate),
            'messageId' => $message->getMessageId(),
        ];
    }//end extractHeaders()

    /**
     * Split a comma-separated address list into an array.
     *
     * Best-effort: addresses with commas inside quoted display names are
     * a known weakness — zbateson's address-header iteration handles the
     * structured case; this string-split is for the simple header-value
     * surface we expose in EmlStructure.headers.
     *
     * @param string|null $raw Raw header value.
     *
     * @return array<int, string>
     */
    private function splitAddressList(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $parts = array_map(
            callback: 'trim',
            array: explode(separator: ',', string: $raw)
        );

        return array_values(
                array: array_filter(
            array: $parts,
            callback: static fn (string $part): bool => $part !== ''
        )
                );
    }//end splitAddressList()

    /**
     * Extract the body parts.
     *
     * @param IMessage $message Parsed message.
     *
     * @return EmlBody
     */
    private function extractBody(IMessage $message): EmlBody
    {
        $plain = $message->getTextContent();
        $html  = $message->getHtmlContent();

        return new EmlBody(
            plainText: ($plain !== null && $plain !== '') ? $plain : null,
            html: ($html !== null && $html !== '') ? $html : null
        );
    }//end extractBody()

    /**
     * Walk attachment parts and build `EmlAttachment` objects.
     *
     * @param IMessage $message Parsed message.
     * @param int      $depth   Current recursion depth (root = 0).
     *
     * @return array<int, EmlAttachment>
     */
    private function extractAttachments(IMessage $message, int $depth): array
    {
        $result   = [];
        $position = 0;
        $parts    = $message->getAllAttachmentParts();

        foreach ($parts as $part) {
            $position++;
            $result[] = $this->buildAttachment(
                part: $part,
                position: $position,
                depth: $depth
            );
        }

        return $result;
    }//end extractAttachments()

    /**
     * Build a single `EmlAttachment` from a MIME part.
     *
     * @param IMessagePart $part     Source part.
     * @param int          $position 1-indexed position in multipart order.
     * @param int          $depth    Current recursion depth.
     *
     * @return EmlAttachment
     */
    private function buildAttachment(IMessagePart $part, int $position, int $depth): EmlAttachment
    {
        $mimeType  = (string) ($part->getContentType(default: 'application/octet-stream') ?? 'application/octet-stream');
        $filename  = $this->resolveFilename(part: $part, position: $position);
        $bytes     = (string) ($part->getContent() ?? '');
        $isInline  = strtolower(string: (string) $part->getContentDisposition(default: 'attachment')) === 'inline';
        $contentId = $this->stripAngleBrackets(raw: $part->getContentId());

        $nestedEml = null;
        if (strtolower(string: $mimeType) === 'message/rfc822') {
            if ($depth < self::MAX_DEPTH) {
                $nestedEml = $this->parseNestedEml(bytes: $bytes, depth: ($depth + 1));
            } else {
                $this->logger->debug(
                    message: '[EmlParser] EML nesting depth limit reached',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'depth' => $depth,
                    ]
                );
            }
        }

        return new EmlAttachment(
            filename: $filename,
            mimeType: $mimeType,
            content: $bytes,
            isInline: $isInline,
            contentId: $contentId,
            nestedEml: $nestedEml
        );
    }//end buildAttachment()

    /**
     * Filename resolution: Content-Disposition `filename` → Content-Type
     * `name` → generated `attachment-<position>`.
     *
     * @param IMessagePart $part     Source part.
     * @param int          $position 1-indexed multipart position.
     *
     * @return string Always non-empty.
     */
    private function resolveFilename(IMessagePart $part, int $position): string
    {
        $fromDisposition = $part->getFilename();
        if (is_string(value: $fromDisposition) === true && $fromDisposition !== '') {
            return $fromDisposition;
        }

        if (method_exists(object_or_class: $part, method: 'getHeaderParameter') === true) {
            $fromType = $part->getHeaderParameter(header: 'Content-Type', param: 'name');
            if (is_string(value: $fromType) === true && $fromType !== '') {
                return $fromType;
            }
        }

        return 'attachment-'.$position;
    }//end resolveFilename()

    /**
     * Parse the bytes of a nested `message/rfc822` attachment.
     *
     * @param string $bytes Decoded message bytes.
     * @param int    $depth Recursion depth of the nested parse.
     *
     * @return EmlStructure|null Null on parse failure (the outer parse
     *                           tolerates a malformed nested EML so the
     *                           rest of the structure is still usable).
     */
    private function parseNestedEml(string $bytes, int $depth): ?EmlStructure
    {
        if ($bytes === '') {
            return null;
        }

        try {
            $message = $this->getParser()->parse(resource: $bytes, attached: false);
            return $this->parseMessage(message: $message, depth: $depth);
        } catch (Throwable $error) {
            $this->logger->debug(
                message: '[EmlParser] Nested EML parse failure ('.get_class($error).')',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'depth' => $depth,
                ]
            );
            return null;
        }//end try
    }//end parseNestedEml()

    /**
     * Strip surrounding angle brackets from a Content-ID value.
     *
     * @param string|null $raw Raw header value.
     *
     * @return string|null
     */
    private function stripAngleBrackets(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim(string: $raw, characters: " \t\n\r\0\x0B<>");
        return $trimmed === '' ? null : $trimmed;
    }//end stripAngleBrackets()

    /**
     * Parse a raw Date header into a DateTimeImmutable.
     *
     * @param string|null $raw Raw header value.
     *
     * @return DateTimeImmutable|null Null when the header is missing or unparseable.
     */
    private function parseDate(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $patterns = [
            \DateTimeInterface::RFC2822,
            \DateTimeInterface::RFC822,
            'D, d M Y H:i:s O',
            'D, d M Y H:i:s O (T)',
            'd M Y H:i:s O',
        ];

        foreach ($patterns as $pattern) {
            $parsed = DateTimeImmutable::createFromFormat(format: $pattern, datetime: $raw);
            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        try {
            return new DateTimeImmutable(datetime: $raw);
        } catch (Throwable) {
            return null;
        }
    }//end parseDate()

    /**
     * Strip a plain-text rendering out of an HTML string for the
     * fallback path of `flatten()`.
     *
     * - Drops `<style>` and `<script>` element content entirely (block-level removal).
     * - Replaces `<br>`, `<p>`, block-level tags with newlines.
     * - Strips remaining tags via `strip_tags`.
     * - Decodes entities via `html_entity_decode`.
     * - Collapses whitespace runs.
     *
     * @param string $html HTML source.
     *
     * @return string Plain text.
     */
    private function htmlToText(string $html): string
    {
        $stripped = preg_replace(
            pattern: '#<(style|script)\b[^>]*>.*?</\1>#is',
            replacement: '',
            subject: $html
        ) ?? $html;

        $withBreaks = preg_replace(
            pattern: '#</?(br|p|div|section|article|h[1-6]|li|tr)[^>]*>#i',
            replacement: "\n",
            subject: $stripped
        ) ?? $stripped;

        $text = strip_tags(string: $withBreaks);
        $text = html_entity_decode(string: $text, flags: (ENT_QUOTES | ENT_HTML5), encoding: 'UTF-8');

        // Collapse whitespace runs.
        $text = preg_replace(pattern: '/[\t ]+/', replacement: ' ', subject: $text) ?? $text;
        $text = preg_replace(pattern: "/\n{3,}/", replacement: "\n\n", subject: $text) ?? $text;

        return trim(string: $text);
    }//end htmlToText()

    /**
     * Ensure a string is UTF-8; transcode from detected encoding when needed.
     *
     * Per the spec, non-UTF-8 input SHOULD be transcoded via mb_detect_encoding
     * + mb_convert_encoding. When detection fails, return the raw bytes
     * unchanged so downstream consumers see at-least-something rather than
     * an exception.
     *
     * @param string $value Possibly-non-UTF-8 string.
     *
     * @return string UTF-8 string (or the raw input when detection fails).
     */
    private function ensureUtf8(string $value): string
    {
        if (mb_check_encoding(value: $value, encoding: 'UTF-8') === true) {
            return $value;
        }

        $candidates = ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-15'];
        $detected   = mb_detect_encoding(string: $value, encodings: $candidates, strict: true);
        if ($detected === false) {
            return $value;
        }

        $converted = mb_convert_encoding(string: $value, to_encoding: 'UTF-8', from_encoding: $detected);
        if (is_string(value: $converted) === true) {
            return $converted;
        }

        return $value;
    }//end ensureUtf8()

    /**
     * Sanitise a string for log output per ADR-005.
     *
     * Replaces patterns that match email addresses and quoted strings
     * with `<redacted>` so log lines never carry PII derived from
     * header / body content.
     *
     * @param string $message Potentially PII-bearing string.
     *
     * @return string Sanitised string safe to log.
     */
    public static function sanitisePiiForLogging(string $message): string
    {
        $patterns = [
            // Email addresses.
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            // Quoted strings (likely names / subjects).
            '/"[^"]*"/',
            // Angle-bracketed values (likely addresses or message IDs).
            '/<[^>]+>/',
        ];

        $result = $message;
        foreach ($patterns as $pattern) {
            $next = preg_replace(pattern: $pattern, replacement: '<redacted>', subject: $result);
            if (is_string(value: $next) === true) {
                $result = $next;
            }
        }

        return $result;
    }//end sanitisePiiForLogging()

    /**
     * Lazily build the underlying mime parser.
     *
     * @return MailMimeParser
     */
    private function getParser(): MailMimeParser
    {
        if ($this->parser === null) {
            $this->parser = new MailMimeParser();
        }

        return $this->parser;
    }//end getParser()
}//end class
