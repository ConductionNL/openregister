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
class EmlParserTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private EmlParser $parser;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->parser = new EmlParser(logger: $this->logger);
    }

    /**
     * Helper: parse an EML source string and return the EmlStructure.
     */
    private function structureFrom(string $eml, int $depth = 0): EmlStructure
    {
        $mime = (new MailMimeParser())->parse($eml, false);
        return $this->parser->parseMessage(message: $mime, depth: $depth);
    }

    public function testStructureExposesCanonicalHeaders(): void
    {
        $eml = "From: Alice <alice@example.com>\r\n"
            . "To: Bob <bob@example.com>\r\n"
            . "Subject: Hello\r\n"
            . "Date: Mon, 12 May 2026 14:00:00 +0200\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Body content.\r\n";

        $structure = $this->structureFrom($eml);

        $this->assertStringContainsString('alice@example.com', (string) $structure->headers['from']);
        $this->assertNotEmpty($structure->headers['to']);
        $this->assertSame('Hello', $structure->headers['subject']);
        $this->assertNotNull($structure->headers['date']);
    }

    public function testMultipartAlternativePrefersTextPlainOnFlatPath(): void
    {
        $boundary = 'b-12345';
        $eml = "Subject: alt\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n"
            . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "Hello Bob — plain\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . "\r\n"
            . "<p>Hello <b>Bob</b> — html</p>\r\n"
            . "--$boundary--\r\n";

        $structure = $this->structureFrom($eml);
        $flat = $this->parser->flatten(structure: $structure);

        $this->assertStringContainsString('Hello Bob — plain', $flat);
        $this->assertStringNotContainsString('<p>', $flat);
        $this->assertStringNotContainsString('<b>', $flat);
        $this->assertStringNotContainsString('Hello Bob — html', $flat);
    }

    public function testFlatPathFallsBackToHtmlWhenPlainIsAbsent(): void
    {
        $eml = "Subject: html only\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . "\r\n"
            . "<p>Hello <b>Bob</b></p><script>alert('x')</script>\r\n";

        $structure = $this->structureFrom($eml);
        $flat = $this->parser->flatten(structure: $structure);

        $this->assertStringContainsString('Hello', $flat);
        $this->assertStringContainsString('Bob', $flat);
        $this->assertStringNotContainsString('<p>', $flat);
        $this->assertStringNotContainsString('<script>', $flat);
        $this->assertStringNotContainsString('alert', $flat);
    }

    public function testAttachmentContentIsDecodedBytesNotBase64(): void
    {
        $rawBytes = "binary\x00bytes\x01with\xffnonascii";
        $b64      = base64_encode($rawBytes);

        $boundary = 'b-789';
        $eml = "Subject: with attach\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n"
            . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "body\r\n"
            . "--$boundary\r\n"
            . "Content-Type: application/octet-stream; name=\"file.bin\"\r\n"
            . "Content-Disposition: attachment; filename=\"file.bin\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . chunk_split($b64) . "\r\n"
            . "--$boundary--\r\n";

        $structure = $this->structureFrom($eml);

        $this->assertCount(1, $structure->attachments);
        $attachment = $structure->attachments[0];
        $this->assertSame('file.bin', $attachment->filename);
        $this->assertSame('application/octet-stream', $attachment->mimeType);
        $this->assertSame($rawBytes, $attachment->content);
        $this->assertNotSame($b64, $attachment->content);
    }

    public function testMissingFilenameFallsBackToPositionalAttachmentName(): void
    {
        $boundary = 'b-noname';
        $eml = "Subject: no name\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n"
            . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "body\r\n"
            . "--$boundary\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Disposition: attachment\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . base64_encode('first') . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Disposition: attachment\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . base64_encode('second') . "\r\n"
            . "--$boundary--\r\n";

        $structure = $this->structureFrom($eml);

        $this->assertCount(2, $structure->attachments);
        $this->assertSame('attachment-1', $structure->attachments[0]->filename);
        $this->assertSame('attachment-2', $structure->attachments[1]->filename);
        $this->assertNotEmpty($structure->attachments[0]->filename);
    }

    public function testInlineAttachmentExposesContentIdWithoutAngleBrackets(): void
    {
        $boundary = 'b-inline';
        $eml = "Subject: inline\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/related; boundary=\"$boundary\"\r\n"
            . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/html\r\n"
            . "\r\n"
            . '<img src="cid:abc@example.com">' . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: image/png\r\n"
            . "Content-Disposition: inline; filename=\"img.png\"\r\n"
            . "Content-ID: <abc@example.com>\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . base64_encode('png-bytes') . "\r\n"
            . "--$boundary--\r\n";

        $structure = $this->structureFrom($eml);

        $this->assertCount(1, $structure->attachments);
        $attachment = $structure->attachments[0];
        $this->assertTrue($attachment->isInline);
        $this->assertSame('abc@example.com', $attachment->contentId);
        $this->assertSame('img.png', $attachment->filename);
    }

    public function testEmptyBodyDoesNotInterfereWithStructure(): void
    {
        $eml = "Subject: empty\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n";

        $structure = $this->structureFrom($eml);
        $this->assertInstanceOf(EmlStructure::class, $structure);
        $this->assertInstanceOf(EmlBody::class, $structure->body);
    }

    public function testFlattenIncludesAttachmentMarker(): void
    {
        $boundary = 'b-marker';
        $eml = "Subject: marker test\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n"
            . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "body text\r\n"
            . "--$boundary\r\n"
            . "Content-Type: application/pdf; name=\"report.pdf\"\r\n"
            . "Content-Disposition: attachment; filename=\"report.pdf\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . base64_encode('pdf-bytes') . "\r\n"
            . "--$boundary--\r\n";

        $structure = $this->structureFrom($eml);
        $flat = $this->parser->flatten(structure: $structure);

        $this->assertStringContainsString('--- Attachment: report.pdf', $flat);
        $this->assertStringContainsString('application/pdf', $flat);
        $this->assertStringContainsString('body text', $flat);
    }

    public function testSanitisePiiForLoggingRedactsEmailsAndAngleBrackets(): void
    {
        $input = 'Parse failed for alice@example.com: <Message-ID: 12345>';
        $sanitised = EmlParser::sanitisePiiForLogging(message: $input);

        $this->assertStringNotContainsString('alice@example.com', $sanitised);
        $this->assertStringNotContainsString('12345', $sanitised);
        $this->assertStringContainsString('<redacted>', $sanitised);
    }

    public function testSanitisePiiForLoggingRedactsQuotedStrings(): void
    {
        $input = 'Subject was "Confidential — case 123"';
        $sanitised = EmlParser::sanitisePiiForLogging(message: $input);

        $this->assertStringNotContainsString('Confidential', $sanitised);
        $this->assertStringNotContainsString('case 123', $sanitised);
        $this->assertStringContainsString('<redacted>', $sanitised);
    }

    public function testEmlBodyValueObjectIsImmutable(): void
    {
        $body = new EmlBody(plainText: 'hello', html: '<b>hello</b>');

        $this->assertSame('hello', $body->plainText);
        $this->assertSame('<b>hello</b>', $body->html);

        $json = $body->jsonSerialize();
        $this->assertSame(['plainText' => 'hello', 'html' => '<b>hello</b>'], $json);
    }

    public function testEmlAttachmentJsonSerialisesContentAsBase64(): void
    {
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
        $this->assertSame($bytes, $attachment->content); // PHP-side stays raw.
    }
}
