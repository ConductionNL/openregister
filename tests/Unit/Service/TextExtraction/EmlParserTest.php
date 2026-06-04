<?php

/**
 * EmlParserTest
 *
 * Unit tests for the EmlParser service covering all spec scenarios.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace Unit\Service\TextExtraction;

use OCA\OpenRegister\Exception\EmlParseException;
use OCA\OpenRegister\Service\TextExtraction\EmlAttachment;
use OCA\OpenRegister\Service\TextExtraction\EmlBody;
use OCA\OpenRegister\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Service\TextExtraction\EmlStructure;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for EmlParser.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
 */
class EmlParserTest extends TestCase
{

    private EmlParser $parser;

    private LoggerInterface&MockObject $logger;

    /**
     * Path to fixture EML files.
     */
    private const FIXTURES_DIR = __DIR__ . '/../../../Fixtures/eml/';

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->parser = new EmlParser(logger: $this->logger);
    }//end setUp()

    /**
     * Create a mock File that returns the given content.
     *
     * @param string $content File content to return.
     * @param int    $fileId  File ID for the mock.
     *
     * @return File&MockObject
     */
    private function makeFileMock(string $content, int $fileId=1): File&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn($content);
        $file->method('getId')->willReturn($fileId);
        return $file;
    }//end makeFileMock()

    /**
     * Load fixture EML file content.
     *
     * @param string $filename EML fixture filename.
     *
     * @return string EML content.
     */
    private function loadFixture(string $filename): string
    {
        return file_get_contents(self::FIXTURES_DIR . $filename);
    }//end loadFixture()

    /**
     * Test: Simple text-only EML produces populated EmlStructure.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseSimpleTextEml(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('simple-text.eml'));
        $structure = $this->parser->parse(file: $file);

        $this->assertInstanceOf(EmlStructure::class, $structure);
        $this->assertNotEmpty($structure->headers['from']);
        $this->assertIsArray($structure->headers['to']);
        $this->assertNotEmpty($structure->headers['subject']);
        $this->assertNotNull($structure->body->plainText);
        $this->assertStringContainsString('Hello Bob', $structure->body->plainText);
        $this->assertNull($structure->body->html);
    }//end testParseSimpleTextEml()

    /**
     * Test: Headers are parsed and normalised correctly.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseHeadersAreNormalised(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('simple-text.eml'));
        $structure = $this->parser->parse(file: $file);

        $this->assertArrayHasKey('from', $structure->headers);
        $this->assertArrayHasKey('to', $structure->headers);
        $this->assertArrayHasKey('cc', $structure->headers);
        $this->assertArrayHasKey('subject', $structure->headers);
        $this->assertArrayHasKey('date', $structure->headers);
        $this->assertArrayHasKey('messageId', $structure->headers);

        // to is always an array.
        $this->assertIsArray($structure->headers['to']);
        $this->assertIsArray($structure->headers['cc']);

        // date is DateTimeImmutable.
        $this->assertInstanceOf(\DateTimeImmutable::class, $structure->headers['date']);

        // messageId has angle brackets stripped.
        $this->assertStringNotContainsString('<', $structure->headers['messageId'] ?? '');
        $this->assertStringNotContainsString('>', $structure->headers['messageId'] ?? '');
    }//end testParseHeadersAreNormalised()

    /**
     * Test: Multipart alternative EML exposes both plainText and html body parts.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseMultipartAlternativeBothBodies(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('multipart-alternative.eml'));
        $structure = $this->parser->parse(file: $file);

        $this->assertNotNull($structure->body->plainText);
        $this->assertNotNull($structure->body->html);
        $this->assertStringContainsString('plain text', $structure->body->plainText);
        $this->assertStringContainsString('<p>', $structure->body->html);
    }//end testParseMultipartAlternativeBothBodies()

    /**
     * Test: HTML-only EML populates html and leaves plainText null.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseHtmlOnlyPopulatesHtmlOnly(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('html-only.eml'));
        $structure = $this->parser->parse(file: $file);

        $this->assertNull($structure->body->plainText);
        $this->assertNotNull($structure->body->html);
    }//end testParseHtmlOnlyPopulatesHtmlOnly()

    /**
     * Test: RFC 2047 encoded-word headers are decoded.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseEncodedWordHeadersAreDecoded(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('encoded-word-headers.eml'));
        $structure = $this->parser->parse(file: $file);

        // From header should be decoded, not still base64.
        $this->assertStringNotContainsString('=?utf-8?', $structure->headers['from']);
        $this->assertStringContainsString('Burgemeester', $structure->headers['from']);
    }//end testParseEncodedWordHeadersAreDecoded()

    /**
     * Test: Malformed Date header results in null date (no exception).
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseMalformedDateResultsInNullDate(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('malformed-date.eml'));
        $structure = $this->parser->parse(file: $file);

        $this->assertNull($structure->headers['date']);
        $this->assertNotNull($structure->body->plainText);
    }//end testParseMalformedDateResultsInNullDate()

    /**
     * Test: Image attachment is listed with correct metadata.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseImageAttachmentHasCorrectMetadata(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('with-image-attachment.eml'));
        $structure = $this->parser->parse(file: $file);

        $this->assertCount(1, $structure->attachments);
        $attachment = $structure->attachments[0];

        $this->assertSame('image.png', $attachment->filename);
        $this->assertSame('image/png', $attachment->mimeType);
        $this->assertFalse($attachment->isInline);
        $this->assertNull($attachment->contentId);
        $this->assertNull($attachment->nestedEml);
        // Content should be decoded bytes (non-empty).
        $this->assertNotEmpty($attachment->content);
    }//end testParseImageAttachmentHasCorrectMetadata()

    /**
     * Test: Inline image attachment has isInline=true and contentId populated.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseInlineImageHasContentId(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('inline-image.eml'));
        $structure = $this->parser->parse(file: $file);

        // Find the inline attachment.
        $inline = null;
        foreach ($structure->attachments as $att) {
            if ($att->mimeType === 'image/png') {
                $inline = $att;
                break;
            }
        }

        $this->assertNotNull($inline);
        $this->assertTrue($inline->isInline);
        $this->assertNotNull($inline->contentId);
        // Angle brackets should be stripped.
        $this->assertStringNotContainsString('<', $inline->contentId);
        $this->assertStringContainsString('image1', $inline->contentId);
    }//end testParseInlineImageHasContentId()

    /**
     * Test: Missing filename falls back to generated 'attachment-<n>'.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseMissingFilenameGeneratedFallback(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('no-filename-attachment.eml'));
        $structure = $this->parser->parse(file: $file);

        $this->assertCount(2, $structure->attachments);

        // First attachment has explicit filename.
        $this->assertSame('first.bin', $structure->attachments[0]->filename);

        // Second attachment has no filename — should get 'attachment-2'.
        $second = $structure->attachments[1];
        $this->assertNotEmpty($second->filename);
        $this->assertSame('attachment-2', $second->filename);
    }//end testParseMissingFilenameGeneratedFallback()

    /**
     * Test: Catastrophically broken input throws EmlParseException from parse().
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseThrowsOnCompletelyBrokenInput(): void
    {
        // Binary garbage that's not a valid EML.
        // Note: mail-mime-parser is very tolerant, so we test with a
        // file-read failure instead.
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(99);
        $file->method('getContent')->willThrowException(new \Exception('Disk failure'));

        $this->expectException(EmlParseException::class);
        $this->parser->parse(file: $file);
    }//end testParseThrowsOnCompletelyBrokenInput()

    /**
     * Test: flatten() produces header block before body.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.2
     */
    public function testFlattenHeaderBlockBeforeBody(): void
    {
        $file   = $this->makeFileMock(content: $this->loadFixture('simple-text.eml'));
        $struct = $this->parser->parse(file: $file);
        $flat   = $this->parser->flatten(structure: $struct);

        $fromPos  = strpos($flat, 'From:');
        $bodyPos  = strpos($flat, 'Hello Bob');

        $this->assertNotFalse($fromPos);
        $this->assertNotFalse($bodyPos);
        $this->assertGreaterThan($fromPos, $bodyPos);
    }//end testFlattenHeaderBlockBeforeBody()

    /**
     * Test: flatten() emits non-extractable attachment marker.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.2
     */
    public function testFlattenNonExtractableAttachmentMarker(): void
    {
        $file   = $this->makeFileMock(content: $this->loadFixture('with-image-attachment.eml'));
        $struct = $this->parser->parse(file: $file);
        $flat   = $this->parser->flatten(structure: $struct);

        $this->assertStringContainsString('image.png (image/png, not extractable)', $flat);
    }//end testFlattenNonExtractableAttachmentMarker()

    /**
     * Test: flatten() uses text/plain only on multipart/alternative (not HTML concatenated).
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.3
     */
    public function testFlattenMultipartAlternativeUsesPlainTextOnly(): void
    {
        $file   = $this->makeFileMock(content: $this->loadFixture('multipart-alternative.eml'));
        $struct = $this->parser->parse(file: $file);
        $flat   = $this->parser->flatten(structure: $struct);

        $this->assertStringContainsString('plain text', $flat);
        $this->assertStringNotContainsString('<p>', $flat);
        $this->assertStringNotContainsString('<b>', $flat);
        // HTML text should NOT be appended after the plain text.
        $htmlCount = substr_count($flat, 'Hello Bob');
        $this->assertSame(1, $htmlCount);
    }//end testFlattenMultipartAlternativeUsesPlainTextOnly()

    /**
     * Test: flatten() with HTML-only body strips HTML to plain text.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.3
     */
    public function testFlattenHtmlBodyIsStrippedToText(): void
    {
        $file   = $this->makeFileMock(content: $this->loadFixture('html-only.eml'));
        $struct = $this->parser->parse(file: $file);
        $flat   = $this->parser->flatten(structure: $struct);

        $this->assertStringNotContainsString('<p>', $flat);
        $this->assertStringNotContainsString('<style>', $flat);
        $this->assertStringNotContainsString('<script>', $flat);
        $this->assertStringContainsString('Hello', $flat);
        // HTML entity &amp; should be decoded to &.
        $this->assertStringContainsString('&', $flat);
    }//end testFlattenHtmlBodyIsStrippedToText()

    /**
     * Test: Nested EML (message/rfc822 attachment) is recursively parsed.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseNestedEmlIsRecursed(): void
    {
        $innerEml = "From: inner@example.com\r\nSubject: Inner\r\nContent-Type: text/plain\r\n\r\nInner body.\r\n";
        $outerEml = "From: outer@example.com\r\nSubject: Outer\r\nContent-Type: multipart/mixed; boundary=\"bndfwd\"\r\n\r\n--bndfwd\r\nContent-Type: text/plain\r\n\r\nOuter body.\r\n--bndfwd\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment; filename=\"forwarded.eml\"\r\n\r\n{$innerEml}\r\n--bndfwd--\r\n";

        $file      = $this->makeFileMock(content: $outerEml);
        $structure = $this->parser->parse(file: $file);

        $this->assertCount(1, $structure->attachments);
        $nested = $structure->attachments[0]->nestedEml;
        $this->assertNotNull($nested);
        $this->assertInstanceOf(EmlStructure::class, $nested);
        $this->assertNotNull($nested->body->plainText);
        $this->assertStringContainsString('Inner body', $nested->body->plainText);
    }//end testParseNestedEmlIsRecursed()

    /**
     * Test: Nesting depth limit (depth > 3) stops recursion.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.1
     */
    public function testParseDepthLimitSetsNestedEmlNull(): void
    {
        // Build a chain A → B → C → D → E where E is at depth 4.
        // Each level uses a UNIQUE boundary to avoid parser confusion.
        $depth4 = "From: d4@example.com\r\nSubject: D4\r\nContent-Type: text/plain\r\n\r\nDeepest level.\r\n";
        $depth3 = "From: d3@example.com\r\nSubject: D3\r\nContent-Type: multipart/mixed; boundary=\"bnd3\"\r\n\r\n"
            ."--bnd3\r\nContent-Type: text/plain\r\n\r\nD3 body\r\n--bnd3\r\nContent-Type: message/rfc822\r\n"
            ."Content-Disposition: attachment; filename=\"d4.eml\"\r\n\r\n{$depth4}\r\n--bnd3--\r\n";
        $depth2 = "From: d2@example.com\r\nSubject: D2\r\nContent-Type: multipart/mixed; boundary=\"bnd2\"\r\n\r\n"
            ."--bnd2\r\nContent-Type: text/plain\r\n\r\nD2 body\r\n--bnd2\r\nContent-Type: message/rfc822\r\n"
            ."Content-Disposition: attachment; filename=\"d3.eml\"\r\n\r\n{$depth3}\r\n--bnd2--\r\n";
        $depth1 = "From: d1@example.com\r\nSubject: D1\r\nContent-Type: multipart/mixed; boundary=\"bnd1\"\r\n\r\n"
            ."--bnd1\r\nContent-Type: text/plain\r\n\r\nD1 body\r\n--bnd1\r\nContent-Type: message/rfc822\r\n"
            ."Content-Disposition: attachment; filename=\"d2.eml\"\r\n\r\n{$depth2}\r\n--bnd1--\r\n";
        $depth0 = "From: d0@example.com\r\nSubject: D0\r\nContent-Type: multipart/mixed; boundary=\"bnd0\"\r\n\r\n"
            ."--bnd0\r\nContent-Type: text/plain\r\n\r\nD0 body\r\n--bnd0\r\nContent-Type: message/rfc822\r\n"
            ."Content-Disposition: attachment; filename=\"d1.eml\"\r\n\r\n{$depth1}\r\n--bnd0--\r\n";

        $this->logger->expects($this->atLeastOnce())->method('debug');

        $file      = $this->makeFileMock(content: $depth0);
        $structure = $this->parser->parse(file: $file);

        // Walk down to depth 3.
        $d0att = $structure->attachments[0];
        $this->assertNotNull($d0att->nestedEml);       // depth 1.
        $d1att = $d0att->nestedEml->attachments[0];
        $this->assertNotNull($d1att->nestedEml);       // depth 2.
        $d2att = $d1att->nestedEml->attachments[0];
        $this->assertNotNull($d2att->nestedEml);       // depth 3 (last allowed).
        $d3att = $d2att->nestedEml->attachments[0];
        // At depth 3 (=MAX_DEPTH), nestedEml is null.
        $this->assertNull($d3att->nestedEml);
    }//end testParseDepthLimitSetsNestedEmlNull()

    /**
     * Test: sanitiseExceptionMessage removes email addresses.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.3
     */
    public function testSanitiseRemovesEmailAddresses(): void
    {
        $raw       = 'Parse failed: From: alice@example.com Subject: Confidential matter';
        $sanitised = $this->parser->sanitiseExceptionMessage($raw);

        $this->assertStringNotContainsString('alice@example.com', $sanitised);
        $this->assertStringContainsString('<redacted>', $sanitised);
    }//end testSanitiseRemovesEmailAddresses()

    /**
     * Test: htmlToText strips style, script, tags and decodes entities.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.3
     */
    public function testHtmlToTextStripsTagsAndDecodesEntities(): void
    {
        $html = '<style>.x{color:red;}</style><script>alert(1)</script><p>Hello <b>World</b></p><p>Test &amp; done.</p>';
        $text = $this->parser->htmlToText(html: $html);

        $this->assertStringNotContainsString('<style>', $text);
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringNotContainsString('<p>', $text);
        $this->assertStringNotContainsString('<b>', $text);
        $this->assertStringContainsString('Hello World', $text);
        $this->assertStringContainsString('&', $text);
    }//end testHtmlToTextStripsTagsAndDecodesEntities()

    /**
     * Test: attachment content field holds decoded bytes (not base64 strings).
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.3
     */
    public function testAttachmentContentIsDecodedBytes(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('with-image-attachment.eml'));
        $structure = $this->parser->parse(file: $file);

        $attachment = $structure->attachments[0];
        // The fixture uses a 1x1 PNG base64 encoded. After decode, we should
        // have PNG magic bytes (89 50 4E 47).
        $bytes = $attachment->content;
        $this->assertNotEmpty($bytes);

        // PNG starts with \x89PNG.
        $this->assertSame("\x89PNG", substr($bytes, 0, 4));

        // Ensure it's NOT a base64 string.
        $this->assertStringNotContainsString('iVBORw', $bytes);
    }//end testAttachmentContentIsDecodedBytes()

    /**
     * Test: EmlStructure implements JsonSerializable and returns valid array.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-5.2
     */
    public function testEmlStructureJsonSerializable(): void
    {
        $file      = $this->makeFileMock(content: $this->loadFixture('simple-text.eml'));
        $structure = $this->parser->parse(file: $file);

        $json = json_encode($structure, JSON_THROW_ON_ERROR);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('headers', $data);
        $this->assertArrayHasKey('body', $data);
        $this->assertArrayHasKey('attachments', $data);
    }//end testEmlStructureJsonSerializable()

}//end class
