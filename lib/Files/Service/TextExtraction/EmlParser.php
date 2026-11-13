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
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-non-utf-8-body-parts-must-be-transcoded-to-utf-8-on-a-best-effort-basis
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Exception\EmlParseException;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Parser for `message/rfc822` files.
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */
class EmlParser {
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
	 * Encodings tried when a body part is not UTF-8.
	 *
	 * @var array<int, string>
	 */
	private const ENCODING_CANDIDATES = ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-15'];

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
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Parse an EML file into a structured `EmlStructure`.
	 *
	 * @param File $file The Nextcloud File node to parse.
	 * @param int $depth Current recursion depth (root = 0).
	 *
	 * @return EmlStructure The parsed structure.
	 *
	 * @throws EmlParseException When the input is irrecoverably malformed.
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	public function parse(File $file, int $depth = 0): EmlStructure {
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
				message: 'EML parse failure (' . get_class($error) . ')'
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
	 * @param int $depth Current recursion depth.
	 *
	 * @return EmlStructure
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	public function parseMessage(IMessage $message, int $depth = 0): EmlStructure {
		$headers = $this->extractHeaders(message: $message);
		$body = $this->extractBody(message: $message);
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
	 * @param int $depth Current recursion depth.
	 *
	 * @return string Flat plain-text output.
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	public function flatten(EmlStructure $structure, int $depth = 0): string {
		$flattener = new EmlFlattener(
			toUtf8: fn (string $value): string => $this->ensureUtf8(value: $value)
		);

		return $flattener->flatten(structure: $structure, depth: $depth);
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
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	private function extractHeaders(IMessage $message): array {
		$rawDate = $message->getHeaderValue(name: 'Date');

		return [
			'from' => $message->getHeaderValue(name: 'From'),
			'to' => $this->splitAddressList(raw: $message->getHeaderValue(name: 'To')),
			'cc' => $this->splitAddressList(raw: $message->getHeaderValue(name: 'Cc')),
			'subject' => $message->getSubject(),
			'date' => (new EmlHeaderParser())->date(raw: $rawDate),
			'messageId' => $message->getMessageId(),
			// RFC 5322 reply-threading headers (REQ-RTH-010): the one message a
			// reply answers, and the whole ancestry. Angle brackets are stripped
			// and the names matched case-insensitively, the same normalisation
			// ReplyThreadResolver applies, so a stored id threads whether it was
			// written `<a1@x>` or `A1@X`.
			// `In-Reply-To` is a single id; zbateson's IdHeader already strips
			// its angle brackets. `References` is a list and getHeaderValue()
			// yields only its first id, so the whole chain is read from the raw
			// header and the brackets stripped here.
			'inReplyTo' => trim((string)$message->getHeaderValue(name: 'In-Reply-To')),
			'references' => self::messageIds(message: $message, name: 'References'),
		];
	}//end extractHeaders()

	/**
	 * Every RFC message id in a header, angle brackets stripped, in order.
	 *
	 * @param IMessage $message The message.
	 * @param string   $name    The header name.
	 *
	 * @return array<int, string> The ids, without their brackets.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
	 */
	private static function messageIds(IMessage $message, string $name): array {
		$header = $message->getHeader($name);
		if ($header === null) {
			return [];
		}

		$raw = (string)$header->getRawValue();
		if (preg_match_all('/<([^<>\\s]+)>/', $raw, $matches) < 1) {
			return [];
		}

		return array_values($matches[1]);
	}//end messageIds()

	/**
	 * Split an RFC 2822 address-list into individual address tokens.
	 *
	 * Naive comma-split breaks on quoted display names containing commas
	 * (e.g. `"Doe, John" <john@example.com>` parses as two bad tokens).
	 * The walk ({@see AddressListSplitter}) preserves commas inside
	 * double-quoted strings and inside angle-bracketed addresses (the two
	 * contexts where RFC 2822 permits structural characters to appear).
	 *
	 * Backslash-escaped quotes inside quoted strings are honoured.
	 *
	 * @param string|null $raw Raw header value.
	 *
	 * @return array<int, string>
	 */
	private function splitAddressList(?string $raw): array {
		if ($raw === null || $raw === '') {
			return [];
		}

		return (new EmlHeaderParser())->addresses(raw: $raw);
	}//end splitAddressList()

	/**
	 * Extract the body parts.
	 *
	 * @param IMessage $message Parsed message.
	 *
	 * @return EmlBody
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-non-utf-8-body-parts-must-be-transcoded-to-utf-8-on-a-best-effort-basis
	 */
	private function extractBody(IMessage $message): EmlBody {
		return new EmlBody(
			plainText: $this->nonEmptyString(value: $message->getTextContent()),
			html: $this->nonEmptyString(value: $message->getHtmlContent())
		);
	}//end extractBody()

	/**
	 * Walk attachment parts and build `EmlAttachment` objects.
	 *
	 * @param IMessage $message Parsed message.
	 * @param int $depth Current recursion depth (root = 0).
	 *
	 * @return array<int, EmlAttachment>
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	private function extractAttachments(IMessage $message, int $depth): array {
		$result = [];
		$position = 0;
		foreach ($message->getAllAttachmentParts() as $part) {
			$position++;
			$result[] = $this->buildAttachment(
				attachment: new EmlAttachmentPart(part: $part, position: $position, depth: $depth)
			);
		}

		return $result;
	}//end extractAttachments()

	/**
	 * Build a single `EmlAttachment` from a MIME part.
	 *
	 * @param EmlAttachmentPart $attachment The part, its position and the current recursion depth.
	 *
	 * @return EmlAttachment
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	private function buildAttachment(EmlAttachmentPart $attachment): EmlAttachment {
		$mimeType = (string)($attachment->part->getContentType(default: 'application/octet-stream') ?? 'application/octet-stream');
		$filename = $this->resolveFilename(attachment: $attachment);
		$bytes = (string)($attachment->part->getContent() ?? '');
		$nestedEml = null;
		if (strtolower(string: $mimeType) === 'message/rfc822') {
			$nestedEml = $this->nestedEmlAt(bytes: $bytes, depth: $attachment->depth);
		}

		return new EmlAttachment(
			file: new EmlAttachmentFile(filename: $filename, mimeType: $mimeType, content: $bytes),
			isInline: strtolower(string: (string)$attachment->part->getContentDisposition(default: 'attachment')) === 'inline',
			contentId: $this->stripAngleBrackets(raw: $attachment->part->getContentId()),
			nestedEml: $nestedEml
		);
	}//end buildAttachment()

	/**
	 * Parse a nested EML found at a depth, or log that the depth limit is reached.
	 *
	 * @param string $bytes Decoded message bytes.
	 * @param int    $depth Depth of the message that holds the attachment.
	 *
	 * @return EmlStructure|null Null at the depth limit or on a parse failure.
	 */
	private function nestedEmlAt(string $bytes, int $depth): ?EmlStructure {
		if ($depth < self::MAX_DEPTH) {
			return $this->parseNestedEml(bytes: $bytes, depth: ($depth + 1));
		}

		$this->logger->debug(
			message: '[EmlParser] EML nesting depth limit reached',
			context: ['file' => __FILE__, 'line' => __LINE__, 'depth' => $depth]
		);
		return null;
	}//end nestedEmlAt()

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
	 * @param EmlAttachmentPart $attachment Source part and its 1-indexed multipart position.
	 *
	 * @return string Always non-empty, always free of path components.
	 */
	private function resolveFilename(EmlAttachmentPart $attachment): string {
		$sanitised = $this->sanitiseFilename(raw: ($this->rawFilename(attachment: $attachment) ?? ''));
		if ($sanitised === '') {
			return 'attachment-' . $attachment->position;
		}

		return $sanitised;
	}//end resolveFilename()

	/**
	 * The sender's filename: Content-Disposition `filename`, else Content-Type `name`.
	 *
	 * @param EmlAttachmentPart $attachment Source part.
	 *
	 * @return string|null The unsanitised name, or null when the part has none.
	 */
	private function rawFilename(EmlAttachmentPart $attachment): ?string {
		return $this->nonEmptyString(value: $attachment->part->getFilename()) ?? $this->contentTypeName(attachment: $attachment);
	}//end rawFilename()

	/**
	 * The Content-Type `name` parameter of a part, when the part exposes it.
	 *
	 * @param EmlAttachmentPart $attachment Source part.
	 *
	 * @return string|null The name, or null.
	 */
	private function contentTypeName(EmlAttachmentPart $attachment): ?string {
		$part = $attachment->part;
		if (method_exists(object_or_class: $part, method: 'getHeaderParameter') === false) {
			return null;
		}

		// Positional args here: zbateson's IMessagePart subclasses
		// expose getHeaderParameter but the interface does not, so
		// PHPStan analyses against a generic stdClass fallback and
		// would reject named-parameter calls.
		return $this->nonEmptyString(value: $part->getHeaderParameter('Content-Type', 'name'));
	}//end contentTypeName()

	/**
	 * A header-derived value when it is a non-empty string, else null.
	 *
	 * @param string|null $value The value.
	 *
	 * @return string|null
	 */
	private function nonEmptyString(?string $value): ?string {
		if ($value !== null && $value !== '') {
			return $value;
		}

		return null;
	}//end nonEmptyString()

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
	private function sanitiseFilename(string $raw): string {
		// Normalise Windows-style separators FIRST so basename() — which on
		// POSIX hosts only splits on `/` — can correctly strip the
		// directory components a sender embedded with `\`.
		$normalised = (string)preg_replace(pattern: '#\\\\+#', replacement: '/', subject: $raw);
		$leaf = trim(string: basename(path: $normalised), characters: " \t\n\r\0\x0B");
		if ($leaf === '' || preg_match(pattern: '/^\.+$/', subject: $leaf) === 1) {
			return '';
		}

		return $leaf;
	}//end sanitiseFilename()

	/**
	 * Parse the bytes of a nested `message/rfc822` attachment.
	 *
	 * @param string $bytes Decoded message bytes.
	 * @param int $depth Recursion depth of the nested parse.
	 *
	 * @return EmlStructure|null Null on parse failure (the outer parse
	 *                           tolerates a malformed nested EML so the
	 *                           rest of the structure is still usable).
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	private function parseNestedEml(string $bytes, int $depth): ?EmlStructure {
		if ($bytes === '') {
			return null;
		}

		try {
			$message = $this->getParser()->parse(resource: $bytes, attached: false);
			return $this->parseMessage(message: $message, depth: $depth);
		} catch (Throwable $error) {
			$this->logger->debug(
				message: '[EmlParser] Nested EML parse failure (' . get_class($error) . ')',
				context: ['file' => __FILE__, 'line' => __LINE__, 'depth' => $depth]
			);
			return null;
		}
	}//end parseNestedEml()

	/**
	 * Strip surrounding angle brackets from a Content-ID value.
	 *
	 * @param string|null $raw Raw header value.
	 *
	 * @return string|null
	 */
	private function stripAngleBrackets(?string $raw): ?string {
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
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-non-utf-8-body-parts-must-be-transcoded-to-utf-8-on-a-best-effort-basis
	 */
	private function ensureUtf8(string $value): string {
		if (mb_check_encoding(value: $value, encoding: 'UTF-8') === true) {
			return $value;
		}

		$detected = mb_detect_encoding(string: $value, encodings: self::ENCODING_CANDIDATES, strict: true);
		if ($detected === false) {
			$this->logUndetectedEncoding(value: $value);
			return $value;
		}

		return $this->transcodeToUtf8(value: $value, from: $detected);
	}//end ensureUtf8()

	/**
	 * Log that the encoding of a non-UTF-8 string could not be detected.
	 *
	 * @param string $value The string, logged by length only.
	 *
	 * @return void
	 */
	private function logUndetectedEncoding(string $value): void {
		$this->logger->error(
			message: '[EmlParser] Non-UTF-8 input — encoding detection failed; returning raw bytes',
			context: ['file' => __FILE__, 'line' => __LINE__, 'value_bytes' => strlen($value), 'candidates' => self::ENCODING_CANDIDATES]
		);
	}//end logUndetectedEncoding()

	/**
	 * Transcode a string to UTF-8, logging and returning it unchanged when that fails.
	 *
	 * @param string $value The string.
	 * @param string $from  Its detected encoding.
	 *
	 * @return string UTF-8 string (or the raw input when transcoding fails).
	 */
	private function transcodeToUtf8(string $value, string $from): string {
		$converted = mb_convert_encoding(string: $value, to_encoding: 'UTF-8', from_encoding: $from);
		if (is_string(value: $converted) === true) {
			return $converted;
		}

		$this->logger->error(
			message: '[EmlParser] Non-UTF-8 input — transcoding to UTF-8 failed; returning raw bytes',
			context: ['file' => __FILE__, 'line' => __LINE__, 'value_bytes' => strlen($value), 'detected_from' => $from, 'target_encoding' => 'UTF-8']
		);
		return $value;
	}//end transcodeToUtf8()

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
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	public static function sanitisePiiForLogging(string $message): string {
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
	private function getParser(): MailMimeParser {
		if ($this->parser === null) {
			$this->parser = new MailMimeParser();
		}

		return $this->parser;
	}//end getParser()
}//end class
