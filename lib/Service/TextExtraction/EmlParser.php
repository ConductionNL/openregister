<?php

/**
 * EmlParser
 *
 * Wraps zbateson/mail-mime-parser to provide flat-text extraction and
 * structured parsing of EML (message/rfc822) files for OpenRegister.
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

    /**
     * Constructor.
     *
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
        ];
    }//end extractHeaders()

    /**
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
        );
    }//end extractBody()

    /**
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
            return null;
        }
    }//end parseDate()

    /**
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
     */
    private function ensureUtf8(string $value): string
    {
        if (mb_check_encoding(value: $value, encoding: 'UTF-8') === true) {
            return $value;
        }

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
}//end class
