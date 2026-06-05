<?php

/**
<<<<<<< HEAD
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
=======
 * EmlParser
 *
 * Wraps zbateson/mail-mime-parser to provide flat-text extraction and
 * structured parsing of EML (message/rfc822) files for OpenRegister.
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
<<<<<<< HEAD
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-31
=======
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
<<<<<<< HEAD
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Parser for `message/rfc822` files.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   MIME parsing requires several collaborating types
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex MIME/RFC 2822 parsing logic
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
=======

/**
 * Shared EML parser used by both the flat-text extraction path
 * (TextExtractionService::extractEml) and the structured-parse path
 * (TextExtractionService::parseEmlStructured).
 *
 * Design notes:
 * - parse()   → returns EmlStructure (the rich value-object)
 * - flatten() → converts an EmlStructure to a flat plain-text string
 *
 * TextExtractionService delegates to this class; all zbateson API calls live
 * here so the two callers stay in sync.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Multiple value-object types required for EML structure
 */
class EmlParser
{

    /**
     * Maximum recursion depth for nested message/rfc822 attachments.
     * Depth 0 = root message, depth 3 = deepest allowed nested EML.
     */
    private const MAX_DEPTH = 3;

    /**
     * MIME types that the flat-path can recursively extract text from.
     * The TextExtractionService handles each; EmlParser delegates back to it.
     */
    private const EXTRACTABLE_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'message/rfc822',
    ];

    /**
     * Lazy-resolved callable returning TextExtractionService.
     * Injected to break the circular DI dependency.
     *
     * @var \Closure|null
     */
    private ?\Closure $textExtractionServiceResolver;
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    /**
     * Constructor.
     *
<<<<<<< HEAD
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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
     * @SuppressWarnings(PHPMD.NPathComplexity)      Nested attachment-tree traversal
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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

            $rendered = (string) $value;
            if ($key === 'date' && $value instanceof DateTimeImmutable) {
                $rendered = $value->format(DateTimeImmutable::ATOM);
            } else if (is_array($value) === true) {
                $rendered = implode(', ', $value);
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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
=======
     * @param LoggerInterface $logger                        PSR logger.
     * @param \Closure|null   $textExtractionServiceResolver Closure returning
     *                                                       TextExtractionService;
     *                                                       may be null in unit tests.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        ?\Closure $textExtractionServiceResolver=null
    ) {
        $this->textExtractionServiceResolver = $textExtractionServiceResolver;
    }//end __construct()

    /**
     * Parse an EML file into a structured EmlStructure value object.
     *
     * Throws EmlParseException on irrecoverable failure (never returns null
     * or a partially-populated structure). The exception message is sanitised
     * to contain no PII per ADR-005.
     *
     * @param File $file  Nextcloud file node for the EML.
     * @param int  $depth Current recursion depth (0 = root call).
     *
     * @return EmlStructure
     *
     * @throws EmlParseException On irrecoverable parse failure.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.3
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.6
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complex EML parsing with multiple header/body/attachment paths
     */
    public function parse(File $file, int $depth=0): EmlStructure
    {
        $fileId = $file->getId();

        try {
            $rawContent = $file->getContent();
        } catch (Throwable $e) {
            throw new EmlParseException(
                message: "Failed to read EML file (fileId={$fileId}, mimeType=message/rfc822): ".get_class($e),
                previous: $e
            );
        }

        return $this->parseString(rawContent: $rawContent, fileId: $fileId, depth: $depth);
    }//end parse()

    /**
     * Parse raw EML string content into an EmlStructure.
     *
     * Used internally for recursive nested-EML parsing.
     *
     * @param string   $rawContent Raw EML bytes.
     * @param int|null $fileId     Nextcloud file ID for log context (null for nested).
     * @param int      $depth      Current recursion depth.
     *
     * @return EmlStructure
     *
     * @throws EmlParseException On irrecoverable parse failure.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complex EML parsing requires multiple steps
     */
    public function parseString(string $rawContent, ?int $fileId=null, int $depth=0): EmlStructure
    {
        try {
            $parser  = new MailMimeParser();
            $message = $parser->parse(resource: $rawContent, attached: false);
        } catch (Throwable $e) {
            $sanitisedMsg = $this->sanitiseExceptionMessage(message: $e->getMessage());
            $exClass      = get_class($e);
            throw new EmlParseException(
                message: "EML parse failed (fileId={$fileId}, exClass={$exClass}): {$sanitisedMsg}",
                previous: $e
            );
        }

        try {
            $headers     = $this->extractHeaders(message: $message);
            $body        = $this->extractBody(message: $message);
            $attachments = $this->extractAttachments(message: $message, depth: $depth, fileId: $fileId);

            return new EmlStructure(
                headers: $headers,
                body: $body,
                attachments: $attachments
            );
        } catch (EmlParseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $sanitisedMsg = $this->sanitiseExceptionMessage(message: $e->getMessage());
            $exClass      = get_class($e);
            throw new EmlParseException(
                message: "EML structure extraction failed (fileId={$fileId}, exceptionClass={$exClass}): {$sanitisedMsg}",
                previous: $e
            );
        }//end try
    }//end parseString()

    /**
     * Convert an EmlStructure to a flat plain-text string.
     *
     * Output order:
     *  1. Header block (From / To / Cc / Subject / Date)
     *  2. Blank line separator
     *  3. Body text (plain-text preferred; HTML-stripped fallback)
     *  4. Attachment markers / inlined extracted text
     *
     * Does NOT throw; operates on an already-parsed structure.
     *
     * @param EmlStructure  $structure      Parsed EML structure.
     * @param int           $depth          Current recursion depth.
     * @param int|null      $fileId         Nextcloud file ID for log context.
     * @param callable|null $bytesExtractor Optional callable(string $bytes, string $mimeType): ?string
     *                                      for extracting text from non-EML attachment bytes. When
     *                                      null, non-EML extractable attachments emit markers only.
     *
     * @return string Flat plain-text representation.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.4
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.5
     */
    public function flatten(EmlStructure $structure, int $depth=0, ?int $fileId=null, ?callable $bytesExtractor=null): string
    {
        $parts = [];

        // 1. Header block.
        $headerLines = $this->buildHeaderBlock(headers: $structure->headers);
        if ($headerLines !== '') {
            $parts[] = $headerLines;
        }

        // 2. Body — prefer plain-text; strip HTML as fallback.
        $bodyText = $structure->body->plainText;
        if ($bodyText === null || trim($bodyText) === '') {
            $bodyText = $structure->body->html !== null ? $this->htmlToText(html: $structure->body->html) : '';
        }

        if (trim($bodyText) !== '') {
            $parts[] = trim($bodyText);
        }

        // 3. Attachments.
        foreach ($structure->attachments as $attachment) {
            $parts[] = $this->flattenAttachment(
                attachment: $attachment,
                depth: $depth,
                fileId: $fileId,
                bytesExtractor: $bytesExtractor
            );
        }

        return implode("\n\n", $parts);
    }//end flatten()

    /**
     * Extract and normalise message headers.
     *
     * @param IMessage $message Parsed message.
     *
     * @return array<string,mixed> Normalised header map.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
     */
    private function extractHeaders(IMessage $message): array
    {
        // From header — use address API to get decoded display name + email.
        $from = $this->formatAddressHeader(message: $message, headerName: 'from', multi: false);

        // To / Cc — may be multi-value.
        $to = $this->extractAddressList(message: $message, headerName: 'to');
        $cc = $this->extractAddressList(message: $message, headerName: 'cc');

        $subject   = $message->getHeaderValue(name: 'subject') ?? '';
        $messageId = $message->getHeaderValue(name: 'message-id');
        if ($messageId !== null) {
            // Strip angle brackets.
            $messageId = trim($messageId, " \t<>");
        }

        // Date — try parsing RFC 2822 / 5322 date string.
        $dateRaw = $message->getHeaderValue(name: 'date');
        $date    = $this->parseDate(dateRaw: $dateRaw);

        return [
            'from'      => $from,
            'to'        => $to,
            'cc'        => $cc,
            'subject'   => $subject,
            'date'      => $date,
            'messageId' => $messageId,
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        ];
    }//end extractHeaders()

    /**
<<<<<<< HEAD
     * Split an RFC 2822 address-list into individual address tokens.
     *
     * Naive comma-split breaks on quoted display names containing commas
     * (e.g. `"Doe, John" <john@example.com>` parses as two bad tokens).
     * The walker below preserves commas inside double-quoted strings and
     * inside angle-bracketed addresses (the two contexts where RFC 2822
     * permits structural characters to appear).
     *
     * Backslash-escaped quotes inside quoted strings are honoured.
     *
     * @param string|null $raw Raw header value.
     *
     * @return array<int, string>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) RFC 2822 address-list parsing state machine
     * @SuppressWarnings(PHPMD.NPathComplexity)      RFC 2822 address-list parsing state machine
     */
    private function splitAddressList(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $tokens  = [];
        $buffer  = '';
        $inQuote = false;
        $inAngle = false;
        $length  = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($char === '\\' && $inQuote === true && $i + 1 < $length) {
                // Honour escaped character inside a quoted display name.
                $buffer .= $char.$raw[++$i];
                continue;
            }

            if ($char === '"' && $inAngle === false) {
                $inQuote = !$inQuote;
                $buffer .= $char;
                continue;
            }

            if ($char === '<' && $inQuote === false) {
                $inAngle = true;
                $buffer .= $char;
                continue;
            }

            if ($char === '>' && $inQuote === false) {
                $inAngle = false;
                $buffer .= $char;
                continue;
            }

            if ($char === ',' && $inQuote === false && $inAngle === false) {
                $tokens[] = trim($buffer);
                $buffer   = '';
                continue;
            }

            $buffer .= $char;
        }//end for

        if ($buffer !== '') {
            $tokens[] = trim($buffer);
        }

        return array_values(
                array: array_filter(
            array: $tokens,
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-31
     */
    private function extractBody(IMessage $message): EmlBody
    {
        $plain = $message->getTextContent();
        $html  = $message->getHtmlContent();

        $plainText = null;
        if ($plain !== null && $plain !== '') {
            $plainText = $plain;
        }

        $htmlText = null;
        if ($html !== null && $html !== '') {
            $htmlText = $html;
        }

        return new EmlBody(
            plainText: $plainText,
            html: $htmlText
=======
     * Format a single address header (From) into a display-friendly string.
     *
     * Returns "Display Name <email>" when a display name is present, or just
     * "email" when it's not. Falls back to the raw header value if the header
     * is not an AddressHeader.
     *
     * @param IMessage $message    Parsed message.
     * @param string   $headerName Header name (case-insensitive).
     * @param bool     $multi      If true, format all addresses in the header.
     *
     * @return string
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
     */
    private function formatAddressHeader(IMessage $message, string $headerName, bool $multi=false): string
    {
        $header = $message->getHeader(name: $headerName);
        if ($header === null) {
            return '';
        }

        if (method_exists($header, 'getAddresses') === false) {
            return $message->getHeaderValue(name: $headerName) ?? '';
        }

        $parts = [];
        foreach ($header->getAddresses() as $addr) {
            $name  = $addr->getName();
            $email = $addr->getEmail();
            if ($name !== '' && $name !== null) {
                $parts[] = "{$name} <{$email}>";
            } else {
                $parts[] = $email;
            }

            if ($multi === false) {
                break;
            }
        }

        return implode(', ', $parts);
    }//end formatAddressHeader()

    /**
     * Extract a list of addresses from a header (To, Cc).
     *
     * Each entry is formatted as "Display Name <email>" or just "email".
     *
     * @param IMessage $message    Parsed message.
     * @param string   $headerName Header name.
     *
     * @return string[]
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
     */
    private function extractAddressList(IMessage $message, string $headerName): array
    {
        $header = $message->getHeader(name: $headerName);
        if ($header === null) {
            return [];
        }

        if (method_exists($header, 'getAddresses') === false) {
            $raw = $message->getHeaderValue(name: $headerName) ?? '';
            return $this->parseAddressList(raw: $raw);
        }

        $result = [];
        foreach ($header->getAddresses() as $addr) {
            $name  = $addr->getName();
            $email = $addr->getEmail();
            if ($name !== '' && $name !== null) {
                $result[] = "{$name} <{$email}>";
            } else {
                $result[] = $email;
            }
        }

        return $result;
    }//end extractAddressList()

    /**
     * Extract body parts (plain-text and HTML).
     *
     * @param IMessage $message Parsed message.
     *
     * @return EmlBody Body value object.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.2
     */
    private function extractBody(IMessage $message): EmlBody
    {
        $plainText = $message->getTextContent();
        $html      = $message->getHtmlContent();

        // Ensure UTF-8 on both.
        if ($plainText !== null) {
            $plainText = $this->ensureUtf8(value: $plainText);
        }

        if ($html !== null) {
            $html = $this->ensureUtf8(value: $html);
        }

        return new EmlBody(
            plainText: ($plainText !== null && trim($plainText) !== '') ? $plainText : null,
            html: ($html !== null && trim($html) !== '') ? $html : null
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        );
    }//end extractBody()

    /**
<<<<<<< HEAD
     * Walk attachment parts and build `EmlAttachment` objects.
     *
     * @param IMessage $message Parsed message.
     * @param int      $depth   Current recursion depth (root = 0).
     *
     * @return array<int, EmlAttachment>
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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
            }

            if ($depth >= self::MAX_DEPTH) {
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
     * Output is sanitised against path-traversal: `basename()` strips any
     * directory components and `..` sequences a malicious sender may have
     * encoded into the filename, and a regex strips path separators that
     * survive on platforms where basename() does not (`\\` on POSIX).
     * Consumers writing this name to disk (`eml-pdf-assembly` materialises
     * attachments to a holding directory) MUST be able to use the value
     * directly as a leaf filename without further validation.
     *
     * @param IMessagePart $part     Source part.
     * @param int          $position 1-indexed multipart position.
     *
     * @return string Always non-empty, always free of path components.
     */
    private function resolveFilename(IMessagePart $part, int $position): string
    {
        $raw = null;

        $fromDisposition = $part->getFilename();
        if (is_string(value: $fromDisposition) === true && $fromDisposition !== '') {
            $raw = $fromDisposition;
        }

        if ($raw === null
            && method_exists(object_or_class: $part, method: 'getHeaderParameter') === true
        ) {
            // Positional args here: zbateson's IMessagePart subclasses
            // expose getHeaderParameter but the interface does not, so
            // PHPStan analyses against a generic stdClass fallback and
            // would reject named-parameter calls.
            $fromType = $part->getHeaderParameter('Content-Type', 'name');
            if (is_string(value: $fromType) === true && $fromType !== '') {
                $raw = $fromType;
            }
        }

        if ($raw === null) {
            return 'attachment-'.$position;
        }

        $sanitised = $this->sanitiseFilename(raw: $raw);
        if ($sanitised === '') {
            return 'attachment-'.$position;
        }

        return $sanitised;
    }//end resolveFilename()

    /**
     * Strip any path components from a sender-controlled filename.
     *
     * `basename()` handles platform-native separators (`/` on POSIX,
     * `\` on Windows); the follow-up regex covers the cross-platform
     * case where a malicious sender embeds `\` in a filename on a
     * POSIX host. Whitespace is trimmed and pure-dot residue (`.`,
     * `..`, `...`) is collapsed to an empty string so traversal
     * sequences cannot survive as the entire filename — but a genuine
     * leading dot on a dotfile (e.g. `.htaccess`) is preserved, since
     * the dot is meaningful filename content rather than traversal.
     *
     * @param string $raw Sender-controlled candidate filename.
     *
     * @return string Leaf-only filename (may be empty if input had no
     *                non-separator content).
     */
    private function sanitiseFilename(string $raw): string
    {
        // Normalise Windows-style separators FIRST so basename() — which on
        // POSIX hosts only splits on `/` — can correctly strip the
        // directory components a sender embedded with `\`.
        $normalised = (string) preg_replace(pattern: '#\\\\+#', replacement: '/', subject: $raw);
        $leaf       = trim(string: basename(path: $normalised), characters: " \t\n\r\0\x0B");
        if ($leaf === '' || preg_match(pattern: '/^\.+$/', subject: $leaf) === 1) {
            return '';
        }

        return $leaf;
    }//end sanitiseFilename()

    /**
     * Parse the bytes of a nested `message/rfc822` attachment.
     *
     * @param string $bytes Decoded message bytes.
     * @param int    $depth Recursion depth of the nested parse.
     *
     * @return EmlStructure|null Null on parse failure (the outer parse
     *                           tolerates a malformed nested EML so the
     *                           rest of the structure is still usable).
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }//end stripAngleBrackets()

    /**
     * Parse a raw Date header into a DateTimeImmutable.
     *
     * @param string|null $raw Raw header value.
     *
     * @return DateTimeImmutable|null Null when the header is missing or unparseable.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromFormat is idiomatic PHP
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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
=======
     * Extract all attachment parts.
     *
     * @param IMessage $message Parsed message.
     * @param int      $depth   Current recursion depth.
     * @param int|null $fileId  File ID for logging.
     *
     * @return EmlAttachment[] Ordered attachment list.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.2
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.3
     */
    private function extractAttachments(IMessage $message, int $depth, ?int $fileId): array
    {
        $attachmentParts = $message->getAllAttachmentParts();
        $attachments     = [];
        $position        = 0;

        foreach ($attachmentParts as $part) {
            $position++;

            $mimeType = $part->getContentType(default: 'application/octet-stream');
            $filename = $this->resolveFilename(part: $part, position: $position);

            // Get decoded binary content.
            $content = $this->getDecodedContent(part: $part);

            $isInline  = strtolower($part->getContentDisposition(default: 'attachment') ?? 'attachment') === 'inline';
            $contentId = $part->getContentId();
            if ($contentId !== null) {
                $contentId = trim($contentId, " \t<>");
            }

            // Recursive nested EML handling.
            $nestedEml = null;
            if ($mimeType === 'message/rfc822') {
                $nestedEml = $this->parseNestedEml(
                    content: $content,
                    depth: $depth,
                    fileId: $fileId
                );
            }

            $attachments[] = new EmlAttachment(
                filename: $filename,
                mimeType: $mimeType,
                content: $content,
                isInline: $isInline,
                contentId: $contentId,
                nestedEml: $nestedEml
            );
        }//end foreach

        return $attachments;
    }//end extractAttachments()

    /**
     * Parse a nested EML from raw content string up to MAX_DEPTH.
     *
     * @param string   $content Raw bytes of the nested EML.
     * @param int      $depth   Current depth of the parent.
     * @param int|null $fileId  Parent file ID for logging.
     *
     * @return EmlStructure|null Parsed nested EML, or null if depth exceeded.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.3
     */
    private function parseNestedEml(string $content, int $depth, ?int $fileId): ?EmlStructure
    {
        if ($depth >= self::MAX_DEPTH) {
            $this->logger->debug(
                message: '[EmlParser] EML nesting depth limit reached',
                context: [
                    'fileId' => $fileId,
                    'depth'  => $depth,
                    'limit'  => self::MAX_DEPTH,
                ]
            );
            return null;
        }

        try {
            return $this->parseString(
                rawContent: $content,
                fileId: $fileId,
                depth: $depth + 1
            );
        } catch (EmlParseException $e) {
            $this->logger->warning(
                message: '[EmlParser] Failed to parse nested EML attachment',
                context: [
                    'fileId'         => $fileId,
                    'depth'          => $depth + 1,
                    'exceptionClass' => get_class($e),
                ]
            );
            return null;
        }
    }//end parseNestedEml()

    /**
     * Resolve attachment filename per spec priority order.
     *
     * Priority: Content-Disposition filename → Content-Type name →
     * generated 'attachment-<n>' (always non-empty).
     *
     * @param mixed $part     Attachment part from zbateson.
     * @param int   $position 1-indexed position in multipart order.
     *
     * @return string Non-empty filename.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.2
     */
    private function resolveFilename(mixed $part, int $position): string
    {
        $filename = $part->getFilename();
        if ($filename !== null && trim($filename) !== '') {
            return $filename;
        }

        return "attachment-{$position}";
    }//end resolveFilename()

    /**
     * Get decoded binary content from an attachment part.
     *
     * Uses getBinaryContentStream() per spec so consumers receive raw bytes
     * that can be embedded as PDF/A-3 file attachments or data: URIs directly.
     *
     * @param mixed $part Attachment part.
     *
     * @return string Decoded binary bytes.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.2
     */
    private function getDecodedContent(mixed $part): string
    {
        try {
            $stream = $part->getBinaryContentStream();
            if ($stream === null) {
                return '';
            }

            return (string) $stream;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[EmlParser] Failed to decode attachment content',
                context: [
                    'exceptionClass' => get_class($e),
                ]
            );
            return '';
        }
    }//end getDecodedContent()

    /**
     * Build the header block for the flat-text output.
     *
     * @param array<string,mixed> $headers Normalised headers from extractHeaders().
     *
     * @return string Header lines (may be empty string if all headers absent).
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.4
     */
    private function buildHeaderBlock(array $headers): string
    {
        $lines = [];

        if (isset($headers['from']) === true && $headers['from'] !== '') {
            $lines[] = 'From: '.$headers['from'];
        }

        if (isset($headers['to']) === true && $headers['to'] !== []) {
            $lines[] = 'To: '.implode('; ', $headers['to']);
        }

        if (isset($headers['cc']) === true && $headers['cc'] !== []) {
            $lines[] = 'Cc: '.implode('; ', $headers['cc']);
        }

        if (isset($headers['subject']) === true && $headers['subject'] !== '') {
            $lines[] = 'Subject: '.$headers['subject'];
        }

        if (isset($headers['date']) === true && $headers['date'] instanceof DateTimeImmutable) {
            $lines[] = 'Date: '.$headers['date']->format(\DateTimeInterface::ISO8601);
        }

        return implode("\n", $lines);
    }//end buildHeaderBlock()

    /**
     * Flatten a single attachment into its text representation.
     *
     * @param EmlAttachment $attachment     The attachment to flatten.
     * @param int           $depth          Current recursion depth.
     * @param int|null      $fileId         File ID for logging.
     * @param callable|null $bytesExtractor Optional text extractor for attachment bytes.
     *
     * @return string Marker line + optional extracted text.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.4
     */
    private function flattenAttachment(
        EmlAttachment $attachment,
        int $depth,
        ?int $fileId,
        ?callable $bytesExtractor=null
    ): string {
        $mimeType = $attachment->mimeType;
        $filename = $attachment->filename;

        if ($mimeType === 'message/rfc822' && $attachment->nestedEml !== null) {
            // Recursively flatten the nested EML.
            $nestedText = $this->flatten(
                structure: $attachment->nestedEml,
                depth: $depth + 1,
                fileId: $fileId,
                bytesExtractor: $bytesExtractor
            );
            return "--- Attachment: {$filename} ---\n{$nestedText}";
        }

        if ($this->isFlatExtractable(mimeType: $mimeType) === true) {
            if ($bytesExtractor !== null) {
                $extractedText = $bytesExtractor($attachment->content, $attachment->mimeType);
            } else {
                $extractedText = $this->extractAttachmentText(attachment: $attachment);
            }

            if ($extractedText !== null && trim($extractedText) !== '') {
                return "--- Attachment: {$filename} ---\n{$extractedText}";
            }
        }

        // Non-extractable or failed extraction.
        return "--- Attachment: {$filename} ({$mimeType}, not extractable) ---";
    }//end flattenAttachment()

    /**
     * Check whether a MIME type can have its text extracted by TextExtractionService.
     *
     * @param string $mimeType MIME type to check.
     *
     * @return bool
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.4
     */
    private function isFlatExtractable(string $mimeType): bool
    {
        if (strpos($mimeType, 'text/') === 0) {
            return true;
        }

        return in_array($mimeType, self::EXTRACTABLE_MIME_TYPES, true);
    }//end isFlatExtractable()

    /**
     * Use TextExtractionService to extract text from an attachment's bytes.
     *
     * Returns null if the resolver is unavailable or extraction fails.
     *
     * @param EmlAttachment $attachment The attachment to extract text from.
     *
     * @return string|null Extracted text, or null on failure.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.4
     */
    private function extractAttachmentText(EmlAttachment $attachment): ?string
    {
        if ($this->textExtractionServiceResolver === null) {
            return null;
        }

        try {
            // Write attachment bytes to a temp file, then create a virtual File.
            $tempPath = tempnam(sys_get_temp_dir(), 'eml_attachment_');
            if ($tempPath === false) {
                return null;
            }

            file_put_contents($tempPath, $attachment->content);

            $service = ($this->textExtractionServiceResolver)();
            if ($service === null) {
                return null;
            }

            $result = $service->extractTextFromPath(
                path: $tempPath,
                mimeType: $attachment->mimeType
            );

            return $result;
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[EmlParser] Attachment text extraction failed',
                context: [
                    'mimeType'       => $attachment->mimeType,
                    'exceptionClass' => get_class($e),
                ]
            );
            return null;
        } finally {
            if (isset($tempPath) === true && file_exists($tempPath) === true) {
                unlink($tempPath);
            }
        }//end try
    }//end extractAttachmentText()

    /**
     * Strip HTML to plain text for the flat-path body fallback.
     *
     * Steps: remove <style>/<script> content; convert block elements to
     * newlines; strip remaining tags; decode entities; collapse whitespace.
     *
     * @param string $html HTML content.
     *
     * @return string Plain-text approximation.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.5
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Used inside flatten()
     */
    public function htmlToText(string $html): string
    {
        // Remove <style>...</style> and <script>...</script> blocks with content.
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;

        // Convert block-level elements to newlines.
        $blockTags = [
            'br',
            'p',
            'div',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'li',
            'tr',
            'blockquote',
            'pre',
            'hr',
        ];
        foreach ($blockTags as $tag) {
            $html = preg_replace('/<'.$tag.'(\s[^>]*)?\s*\/?>/i', "\n", $html) ?? $html;
            $html = preg_replace('/<\/'.$tag.'>/i', "\n", $html) ?? $html;
        }

        // Strip all remaining tags.
        $html = strip_tags($html);

        // Decode HTML entities.
        $html = html_entity_decode(string: $html, flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');

        // Collapse multiple blank lines; normalize whitespace within lines.
        $html = preg_replace('/[ \t]+/', ' ', $html) ?? $html;
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        return trim($html);
    }//end htmlToText()

    /**
     * Parse an RFC 2822 / 5322 date string into a DateTimeImmutable.
     *
     * Returns null on parse failure — per spec, a missing or malformed date
     * must not throw.
     *
     * @param string|null $dateRaw Raw date header value.
     *
     * @return DateTimeImmutable|null
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
     */
    private function parseDate(?string $dateRaw): ?DateTimeImmutable
    {
        if ($dateRaw === null || trim($dateRaw) === '') {
            return null;
        }

        // PHP's DateTimeImmutable constructor handles RFC 2822 natively.
        try {
            return new DateTimeImmutable($dateRaw);
        } catch (Throwable $e) {
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
            return null;
        }
    }//end parseDate()

    /**
<<<<<<< HEAD
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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
     * + mb_convert_encoding. When detection or conversion fails, the raw
     * bytes are returned unchanged so downstream consumers see at-least-
     * something rather than an exception — but the failure is logged at
     * error level per ADR-005's MUST-log on transcoding failure. Operators
     * need visibility into garbled-character situations to triage
     * encoding-sensitive senders.
     *
     * @param string $value Possibly-non-UTF-8 string.
     *
     * @return string UTF-8 string (or the raw input when detection fails).
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-31
=======
     * Split a raw address header value into individual address strings.
     *
     * Handles single addresses and comma-separated lists.
     *
     * @param string $raw Raw header value.
     *
     * @return string[]
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.1
     */
    private function parseAddressList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        // Simple split on comma — not RFC 5321 complete but sufficient for display.
        $parts = explode(',', $raw);
        return array_map('trim', array_filter($parts, static fn($p) => trim($p) !== ''));
    }//end parseAddressList()

    /**
     * Ensure a string is valid UTF-8 via best-effort transcoding.
     *
     * Per spec: when mb_check_encoding fails, run mb_detect_encoding +
     * mb_convert_encoding. Logs the transcode failure (charset only, no content).
     *
     * @param string $value Input string.
     *
     * @return string UTF-8 string.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.3
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    private function ensureUtf8(string $value): string
    {
        if (mb_check_encoding(value: $value, encoding: 'UTF-8') === true) {
            return $value;
        }

<<<<<<< HEAD
        $candidates = ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-15'];
        $detected   = mb_detect_encoding(string: $value, encodings: $candidates, strict: true);
        if ($detected === false) {
            $this->logger->error(
                message: '[EmlParser] Non-UTF-8 input — encoding detection failed; returning raw bytes',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'value_bytes' => strlen($value),
                    'candidates'  => $candidates,
                ]
            );
            return $value;
        }

        $converted = mb_convert_encoding(string: $value, to_encoding: 'UTF-8', from_encoding: $detected);
        if (is_string(value: $converted) === true) {
            return $converted;
        }

        $this->logger->error(
            message: '[EmlParser] Non-UTF-8 input — transcoding to UTF-8 failed; returning raw bytes',
            context: [
                'file'            => __FILE__,
                'line'            => __LINE__,
                'value_bytes'     => strlen($value),
                'detected_from'   => $detected,
                'target_encoding' => 'UTF-8',
            ]
        );
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-30
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
=======
        $detected = mb_detect_encoding(string: $value, encodings: null, strict: false);
        if ($detected !== false) {
            try {
                return mb_convert_encoding(string: $value, to_encoding: 'UTF-8', from_encoding: $detected);
            } catch (Throwable $e) {
                $this->logger->debug(
                    message: '[EmlParser] Charset transcoding failed',
                    context: [
                        'detectedCharset' => $detected,
                        'exceptionClass'  => get_class($e),
                    ]
                );
            }
        }

        // Last resort: force conversion ignoring errors.
        return mb_convert_encoding(string: $value, to_encoding: 'UTF-8', from_encoding: 'UTF-8');
    }//end ensureUtf8()

    /**
     * Sanitise an exception message to remove PII per ADR-005.
     *
     * Replaces email addresses, angle-bracket sequences (headers), quoted
     * strings (subject-like content), and long words (display names) with
     * the literal placeholder <redacted>.
     *
     * @param string $message Raw exception message.
     *
     * @return string Sanitised message safe for logging.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-3.6
     */
    public function sanitiseExceptionMessage(string $message): string
    {
        // Replace email addresses (user@domain.tld).
        $message = preg_replace('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/u', '<redacted>', $message) ?? $message;

        // Replace angle-bracketed header content.
        $message = preg_replace('/<[^>]+>/u', '<redacted>', $message) ?? $message;

        // Replace quoted strings (potential subject / display name content).
        $message = preg_replace('/\"[^\"]{4,}\"/u', '"<redacted>"', $message) ?? $message;
        $message = preg_replace("/\'[^\']{4,}\'/u", "'<redacted>'", $message) ?? $message;

        return $message;
    }//end sanitiseExceptionMessage()
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
}//end class
