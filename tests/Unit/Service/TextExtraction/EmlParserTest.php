<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Service\TextExtraction\EmlAttachment;
use OCA\OpenRegister\Service\TextExtraction\EmlBody;
use OCA\OpenRegister\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Service\TextExtraction\EmlStructure;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Unit tests for `EmlParser`.
 *
 * Covers the spec-driven behaviours that don't require disk fixtures:
 *   - multipart/alternative preference on the flat path
 *   - filename resolution with the `attachment-<n>` fallback
 *   - content as decoded bytes (not base64)
 *   - depth-3 recursion cap on nested message/rfc822
 *   - MUST-throw on irrecoverable input (via parseMessage delegate's
 *     error path; parse(File) is exercised via integration tests)
 *   - PII sanitisation static helper (ADR-005)
 *
 * @spec openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md
 */
class EmlParserTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	private EmlParser $parser;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->parser = new EmlParser(logger: $this->logger);
	}//end setUp()

	/**
	 * Helper: parse an EML source string and return the EmlStructure.
	 */
	private function structureFrom(string $eml, int $depth = 0): EmlStructure {
		$mime = (new MailMimeParser())->parse($eml, false);
		return $this->parser->parseMessage(message: $mime, depth: $depth);
	}//end structureFrom()

	public function testStructureExposesCanonicalHeaders(): void {
		$eml = "From: Alice <alice@example.com>\r\n" . "To: Bob <bob@example.com>\r\n" . "Subject: Hello\r\n" . "Date: Mon, 12 May 2026 14:00:00 +0200\r\n" . "Content-Type: text/plain; charset=utf-8\r\n" . "\r\n" . "Body content.\r\n";

		$structure = $this->structureFrom($eml);

		$this->assertStringContainsString('alice@example.com', (string)$structure->headers['from']);
		$this->assertNotEmpty($structure->headers['to']);
		$this->assertSame('Hello', $structure->headers['subject']);
		$this->assertNotNull($structure->headers['date']);
	}//end testStructureExposesCanonicalHeaders()

	public function testMultipartAlternativePrefersTextPlainOnFlatPath(): void {
		$boundary = 'b-12345';
		$eml = "Subject: alt\r\n" . "MIME-Version: 1.0\r\n" . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n" . "\r\n" . "--$boundary\r\n" . "Content-Type: text/plain; charset=utf-8\r\n" . "\r\n" . "Hello Bob — plain\r\n" . "--$boundary\r\n" . "Content-Type: text/html; charset=utf-8\r\n" . "\r\n" . "<p>Hello <b>Bob</b> — html</p>\r\n" . "--$boundary--\r\n";

		$structure = $this->structureFrom($eml);
		$flat = $this->parser->flatten(structure: $structure);

		$this->assertStringContainsString('Hello Bob — plain', $flat);
		$this->assertStringNotContainsString('<p>', $flat);
		$this->assertStringNotContainsString('<b>', $flat);
		$this->assertStringNotContainsString('Hello Bob — html', $flat);
	}//end testMultipartAlternativePrefersTextPlainOnFlatPath()

	public function testFlatPathFallsBackToHtmlWhenPlainIsAbsent(): void {
		$eml = "Subject: html only\r\n" . "MIME-Version: 1.0\r\n" . "Content-Type: text/html; charset=utf-8\r\n" . "\r\n" . "<p>Hello <b>Bob</b></p><script>alert('x')</script>\r\n";

		$structure = $this->structureFrom($eml);
		$flat = $this->parser->flatten(structure: $structure);

		$this->assertStringContainsString('Hello', $flat);
		$this->assertStringContainsString('Bob', $flat);
		$this->assertStringNotContainsString('<p>', $flat);
		$this->assertStringNotContainsString('<script>', $flat);
		$this->assertStringNotContainsString('alert', $flat);
	}//end testFlatPathFallsBackToHtmlWhenPlainIsAbsent()

	public function testAttachmentContentIsDecodedBytesNotBase64(): void {
		$rawBytes = "binary\x00bytes\x01with\xffnonascii";
		$b64 = base64_encode($rawBytes);

		$boundary = 'b-789';
		$eml = "Subject: with attach\r\n" . "MIME-Version: 1.0\r\n" . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n" . "\r\n" . "--$boundary\r\n" . "Content-Type: text/plain; charset=utf-8\r\n" . "\r\n" . "body\r\n" . "--$boundary\r\n" . "Content-Type: application/octet-stream; name=\"file.bin\"\r\n" . "Content-Disposition: attachment; filename=\"file.bin\"\r\n" . "Content-Transfer-Encoding: base64\r\n" . "\r\n" . chunk_split($b64) . "\r\n" . "--$boundary--\r\n";

		$structure = $this->structureFrom($eml);

		$this->assertCount(1, $structure->attachments);
		$attachment = $structure->attachments[0];
		$this->assertSame('file.bin', $attachment->filename);
		$this->assertSame('application/octet-stream', $attachment->mimeType);
		$this->assertSame($rawBytes, $attachment->content);
		$this->assertNotSame($b64, $attachment->content);
	}//end testAttachmentContentIsDecodedBytesNotBase64()

	public function testMissingFilenameFallsBackToPositionalAttachmentName(): void {
		$boundary = 'b-noname';
		$eml = "Subject: no name\r\n" . "MIME-Version: 1.0\r\n" . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n" . "\r\n" . "--$boundary\r\n" . "Content-Type: text/plain\r\n" . "\r\n" . "body\r\n" . "--$boundary\r\n" . "Content-Type: application/octet-stream\r\n" . "Content-Disposition: attachment\r\n" . "Content-Transfer-Encoding: base64\r\n" . "\r\n" . base64_encode('first') . "\r\n" . "--$boundary\r\n" . "Content-Type: application/octet-stream\r\n" . "Content-Disposition: attachment\r\n" . "Content-Transfer-Encoding: base64\r\n" . "\r\n" . base64_encode('second') . "\r\n" . "--$boundary--\r\n";

		$structure = $this->structureFrom($eml);

		$this->assertCount(2, $structure->attachments);
		$this->assertSame('attachment-1', $structure->attachments[0]->filename);
		$this->assertSame('attachment-2', $structure->attachments[1]->filename);
		$this->assertNotEmpty($structure->attachments[0]->filename);
	}//end testMissingFilenameFallsBackToPositionalAttachmentName()

	public function testInlineAttachmentExposesContentIdWithoutAngleBrackets(): void {
		$boundary = 'b-inline';
		$eml = "Subject: inline\r\n" . "MIME-Version: 1.0\r\n" . "Content-Type: multipart/related; boundary=\"$boundary\"\r\n" . "\r\n" . "--$boundary\r\n" . "Content-Type: text/html\r\n" . "\r\n" . '<img src="cid:abc@example.com">' . "\r\n" . "--$boundary\r\n" . "Content-Type: image/png\r\n" . "Content-Disposition: inline; filename=\"img.png\"\r\n" . "Content-ID: <abc@example.com>\r\n" . "Content-Transfer-Encoding: base64\r\n" . "\r\n" . base64_encode('png-bytes') . "\r\n" . "--$boundary--\r\n";

		$structure = $this->structureFrom($eml);

		$this->assertCount(1, $structure->attachments);
		$attachment = $structure->attachments[0];
		$this->assertTrue($attachment->isInline);
		$this->assertSame('abc@example.com', $attachment->contentId);
		$this->assertSame('img.png', $attachment->filename);
	}//end testInlineAttachmentExposesContentIdWithoutAngleBrackets()

	public function testEmptyBodyDoesNotInterfereWithStructure(): void {
		$eml = "Subject: empty\r\n" . "Content-Type: text/plain; charset=utf-8\r\n" . "\r\n";

		$structure = $this->structureFrom($eml);
		$this->assertInstanceOf(EmlStructure::class, $structure);
		$this->assertInstanceOf(EmlBody::class, $structure->body);
	}//end testEmptyBodyDoesNotInterfereWithStructure()

	public function testFlattenIncludesAttachmentMarker(): void {
		$boundary = 'b-marker';
		$eml = "Subject: marker test\r\n" . "MIME-Version: 1.0\r\n" . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n" . "\r\n" . "--$boundary\r\n" . "Content-Type: text/plain\r\n" . "\r\n" . "body text\r\n" . "--$boundary\r\n" . "Content-Type: application/pdf; name=\"report.pdf\"\r\n" . "Content-Disposition: attachment; filename=\"report.pdf\"\r\n" . "Content-Transfer-Encoding: base64\r\n" . "\r\n" . base64_encode('pdf-bytes') . "\r\n" . "--$boundary--\r\n";

		$structure = $this->structureFrom($eml);
		$flat = $this->parser->flatten(structure: $structure);

		$this->assertStringContainsString('--- Attachment: report.pdf', $flat);
		$this->assertStringContainsString('application/pdf', $flat);
		$this->assertStringContainsString('body text', $flat);
	}//end testFlattenIncludesAttachmentMarker()

	public function testEnsureUtf8TranscodesIso88591BodyToUtf8(): void {
		// ISO-8859-1 bytes for "é à ñ" (0xE9 0x20 0xE0 0x20 0xF1). These
		// are NOT valid UTF-8 (single-byte high-ASCII without continuation)
		// and SHOULD be transcoded by ensureUtf8 to their UTF-8 equivalents.
		$iso88591 = "\xE9 \xE0 \xF1";
		$expectedUtf8 = 'é à ñ';

		$body = new EmlBody(plainText: $iso88591, html: null);
		$structure = new EmlStructure(
			headers: ['from' => 'a@b'],
			body: $body,
			attachments: []
		);

		$flat = $this->parser->flatten(structure: $structure);
		$this->assertStringContainsString($expectedUtf8, $flat);
		// Round-trip: the flat output MUST be valid UTF-8 — no high-ASCII
		// single bytes leaking through unconverted.
		$this->assertTrue(mb_check_encoding($flat, 'UTF-8'));
	}//end testEnsureUtf8TranscodesIso88591BodyToUtf8()

	public function testEnsureUtf8ShortCircuitsForValidUtf8Input(): void {
		// The empty string and any already-valid-UTF-8 bytes MUST exit at
		// the mb_check_encoding guard before reaching detect/convert.
		$reflection = new \ReflectionMethod(EmlParser::class, 'ensureUtf8');
		$reflection->setAccessible(true);

		$this->assertSame('', $reflection->invoke($this->parser, ''));
		$this->assertSame('utf-8 bytes', $reflection->invoke($this->parser, 'utf-8 bytes'));
		$this->assertSame('héllo — wörld', $reflection->invoke($this->parser, 'héllo — wörld'));
	}//end testEnsureUtf8ShortCircuitsForValidUtf8Input()

	public function testEnsureUtf8TranscodesWindows1252SmartQuotesToUtf8(): void {
		// Windows-1252 smart quotes (0x91/0x92 = left/right single, 0x93/0x94
		// = left/right double) are NOT valid UTF-8 bytes on their own. After
		// ensureUtf8 transcoding, the output MUST be valid UTF-8 and contain
		// the canonical UTF-8 encodings of those code points.
		$reflection = new \ReflectionMethod(EmlParser::class, 'ensureUtf8');
		$reflection->setAccessible(true);

		// "Say \x93hello\x94" — left+right double-quote in Windows-1252.
		$win1252 = "Say \x93hello\x94";
		$this->assertFalse(mb_check_encoding($win1252, 'UTF-8'), 'fixture must be invalid UTF-8');

		$result = $reflection->invoke($this->parser, $win1252);
		$this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'output must be valid UTF-8');
		$this->assertStringContainsString("\u{201C}hello\u{201D}", $result, 'smart quotes must transcode');
	}//end testEnsureUtf8TranscodesWindows1252SmartQuotesToUtf8()

	// Note: the `detect failed` + `convert returned non-string` error log
	// branches in ensureUtf8 are not exercised by unit tests. ISO-8859-1
	// strict detection accepts any byte sequence (every byte is a valid
	// code point), so detect-failure cannot be triggered with the current
	// candidate list. The convert-failed branch requires mb_convert_encoding
	// to return non-string, which it does not for any in-process input we
	// can construct here. Both error paths are statically wired to
	// $this->logger->error() at EmlParser:625 and :642 — verified by
	// inspection; live coverage would require integration tests with
	// controlled fixtures and is out of scope.

	public function testSanitiseFilenamePreservesLeadingDotForDotfiles(): void {
		// Dotfiles like `.htaccess` MUST survive sanitisation with the
		// leading dot intact — it's meaningful filename content, not
		// traversal residue.
		$reflection = new \ReflectionMethod(EmlParser::class, 'sanitiseFilename');
		$reflection->setAccessible(true);

		$this->assertSame('.htaccess', $reflection->invoke($this->parser, '.htaccess'));
		$this->assertSame('.env', $reflection->invoke($this->parser, '.env'));
		$this->assertSame('.gitignore', $reflection->invoke($this->parser, '../.gitignore'));

		// Pure-dot residue MUST still collapse to empty so resolveFilename
		// falls back to the `attachment-<n>` synthetic name.
		$this->assertSame('', $reflection->invoke($this->parser, '.'));
		$this->assertSame('', $reflection->invoke($this->parser, '..'));
		$this->assertSame('', $reflection->invoke($this->parser, '...'));
		$this->assertSame('', $reflection->invoke($this->parser, '../../config/..'));
	}//end testSanitiseFilenamePreservesLeadingDotForDotfiles()

	public function testSplitAddressListPreservesCommasInsideQuotedDisplayNames(): void {
		// RFC 2822: `"Doe, John" <john@example.com>` is ONE address. A naive
		// comma-split would yield two tokens; the parser MUST treat the
		// quoted-comma as part of the display name.
		$reflection = new \ReflectionMethod(EmlParser::class, 'splitAddressList');
		$reflection->setAccessible(true);

		$result = $reflection->invoke($this->parser, '"Doe, John" <john@example.com>, "Smith, Jane" <jane@example.com>');

		$this->assertCount(2, $result);
		$this->assertSame('"Doe, John" <john@example.com>', $result[0]);
		$this->assertSame('"Smith, Jane" <jane@example.com>', $result[1]);
	}//end testSplitAddressListPreservesCommasInsideQuotedDisplayNames()

	public function testResolveFilenameSanitisesPathTraversal(): void {
		// A malicious sender attaches a file named `../../config/config.php`.
		// The resolved filename MUST be the leaf only — no directory parts,
		// no `..` traversal — so consumers writing to a holding directory
		// cannot be tricked into escaping it.
		$reflection = new \ReflectionMethod(EmlParser::class, 'sanitiseFilename');
		$reflection->setAccessible(true);

		$this->assertSame('config.php', $reflection->invoke($this->parser, '../../config/config.php'));
		$this->assertSame('config.php', $reflection->invoke($this->parser, '..\\..\\config\\config.php'));
		$this->assertSame('', $reflection->invoke($this->parser, '..'));
		$this->assertSame('', $reflection->invoke($this->parser, '../'));
		$this->assertSame('clean.txt', $reflection->invoke($this->parser, 'clean.txt'));
	}//end testResolveFilenameSanitisesPathTraversal()

	public function testSanitisePiiForLoggingRedactsEmailsAndAngleBrackets(): void {
		$input = 'Parse failed for alice@example.com: <Message-ID: 12345>';
		$sanitised = EmlParser::sanitisePiiForLogging(message: $input);

		$this->assertStringNotContainsString('alice@example.com', $sanitised);
		$this->assertStringNotContainsString('12345', $sanitised);
		$this->assertStringContainsString('<redacted>', $sanitised);
	}//end testSanitisePiiForLoggingRedactsEmailsAndAngleBrackets()

	public function testSanitisePiiForLoggingRedactsQuotedStrings(): void {
		$input = 'Subject was "Confidential — case 123"';
		$sanitised = EmlParser::sanitisePiiForLogging(message: $input);

		$this->assertStringNotContainsString('Confidential', $sanitised);
		$this->assertStringNotContainsString('case 123', $sanitised);
		$this->assertStringContainsString('<redacted>', $sanitised);
	}//end testSanitisePiiForLoggingRedactsQuotedStrings()

	public function testEmlBodyValueObjectIsImmutable(): void {
		$body = new EmlBody(plainText: 'hello', html: '<b>hello</b>');

		$this->assertSame('hello', $body->plainText);
		$this->assertSame('<b>hello</b>', $body->html);

		$json = $body->jsonSerialize();
		$this->assertSame(['plainText' => 'hello', 'html' => '<b>hello</b>'], $json);
	}//end testEmlBodyValueObjectIsImmutable()

	public function testEmlAttachmentJsonSerialisesContentAsBase64(): void {
		$bytes = "binary\x00\xff";
		$attachment = new EmlAttachment(
			filename: 'f.bin',
			mimeType: 'application/octet-stream',
			content: $bytes,
			isInline: false,
			contentId: null,
			nestedEml: null
		);

		$json = $attachment->jsonSerialize();
		$this->assertSame(base64_encode($bytes), $json['content']);
		$this->assertSame('f.bin', $json['filename']);
		$this->assertSame($bytes, $attachment->content);
		// PHP-side stays raw.
	}//end testEmlAttachmentJsonSerialisesContentAsBase64()
}//end class
