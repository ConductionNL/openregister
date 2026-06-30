<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\AnonymisedEmlStructure;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Service\TextExtraction\EmlParser;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for the message/rfc822 anonymise path in DocumentProcessingHandler.
 *
 * Covers the FS-free behaviours: decoded body + header redaction (incl. the
 * base64-leak guard), unsupported-attachment flagging, nested-EML recursion,
 * placeholder consistency, redactor routing, and the EML guard on
 * anonymizeDocument. Full attachment BYTE redaction (PhpWord / PDF on
 * materialised temp files) is covered by integration tests against real storage.
 *
 * @spec openspec/changes/anonymise-eml-structured/specs/eml-anonymisation/spec.md
 */
class DocumentProcessingHandlerEmlTest extends TestCase
{

    private DocumentProcessingHandler $handler;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $relationMapper = $this->createMock(EntityRelationMapper::class);
        $relationMapper->method('findSkippedEntityValuesForFile')->willReturn([]);
        $relationMapper->method('findEntityIdsByValueForFile')->willReturn([]);

        $this->handler = new DocumentProcessingHandler(
            rootFolder: $this->createMock(IRootFolder::class),
            userSession: $this->createMock(IUserSession::class),
            logger: $this->createMock(LoggerInterface::class),
            entityRelationMapper: $relationMapper,
            l10n: null,
            emlParser: new EmlParser(logger: $this->createMock(LoggerInterface::class))
        );

    }//end setUp()


    /**
     * Build a File-node mock whose fopen() yields the given EML string and
     * whose parent provides a throwaway work folder.
     *
     * @param string $eml The raw EML source.
     *
     * @return File
     */
    private function emlNode(string $eml): File
    {
        $workFolder = $this->createMock(Folder::class);
        $workFolder->method('delete');

        $parent = $this->createMock(Folder::class);
        $parent->method('nodeExists')->willReturn(false);
        $parent->method('newFolder')->willReturn($workFolder);

        $node = $this->createMock(File::class);
        $node->method('getId')->willReturn(0);
        $node->method('getName')->willReturn('message.eml');
        $node->method('getMimeType')->willReturn('message/rfc822');
        $node->method('getParent')->willReturn($parent);
        $node->method('fopen')->willReturnCallback(
            function () use ($eml) {
                $stream = fopen('php://temp', 'r+');
                fwrite($stream, $eml);
                rewind($stream);
                return $stream;
            }
        );

        return $node;
    }//end emlNode()


    /**
     * 4.1 — a base64-encoded HTML body part is redacted on DECODED content;
     * the raw-byte fallback (which would miss the encoded part) is never used.
     *
     * @return void
     */
    public function testBase64BodyIsRedactedOnDecodedContent(): void
    {
        $html    = '<p>BSN: 111222333</p>';
        $b64     = chunk_split(base64_encode($html), 76, "\r\n");
        $eml     = "From: Sender <sender@example.nl>\r\n"
            ."To: rcpt@example.nl\r\n"
            ."Subject: Test\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 7bit\r\n\r\nBSN: 111222333\r\n"
            ."--b1\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".$b64."\r\n"
            ."--b1--\r\n";

        $result = $this->handler->anonymizeEmlStructured(
            node: $this->emlNode($eml),
            entities: [['text' => '111222333', 'entityType' => 'SSN', 'key' => '1']]
        );

        $this->assertInstanceOf(AnonymisedEmlStructure::class, $result);
        $this->assertStringContainsString('[SSN: 1]', (string) $result->body->plain);
        $this->assertStringContainsString('[SSN: 1]', (string) $result->body->html);
        $this->assertStringNotContainsString('111222333', (string) $result->body->plain);
        $this->assertStringNotContainsString('111222333', (string) $result->body->html);

    }//end testBase64BodyIsRedactedOnDecodedContent()


    /**
     * 4.2 — PII in display headers (From) is redacted; Reply-To is included.
     *
     * @return void
     */
    public function testDisplayHeadersAreRedacted(): void
    {
        // The parser's header values carry the email address (the display name
        // is dropped by getHeaderValue), so the address is the redactable PII.
        $eml = "From: Jan de Vries <jan@example.nl>\r\n"
            ."Reply-To: jan@example.nl\r\n"
            ."To: rcpt@example.nl\r\n"
            ."Subject: Bericht voor jan@example.nl\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n\r\nHi.\r\n";

        $result = $this->handler->anonymizeEmlStructured(
            node: $this->emlNode($eml),
            entities: [['text' => 'jan@example.nl', 'entityType' => 'EMAIL', 'key' => '1']]
        );

        $this->assertStringContainsString('[EMAIL: 1]', (string) $result->headers['from']);
        $this->assertStringContainsString('[EMAIL: 1]', (string) $result->headers['subject']);
        $this->assertStringContainsString('[EMAIL: 1]', (string) $result->headers['replyTo']);
        $this->assertStringNotContainsString('jan@example.nl', (string) $result->headers['from']);

    }//end testDisplayHeadersAreRedacted()


    /**
     * 4.5 — an attachment whose MIME no redactor handles is flagged
     * unsupported with no content (placeholder policy).
     *
     * @return void
     */
    public function testUnsupportedAttachmentIsFlaggedAndDropped(): void
    {
        $payload = chunk_split(base64_encode('binary-spreadsheet'), 76, "\r\n");
        $eml     = "From: s@example.nl\r\nTo: r@example.nl\r\nSubject: With sheet\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"m1\"\r\n\r\n"
            ."--m1\r\nContent-Type: text/plain\r\n\r\nSee attached.\r\n"
            ."--m1\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; name=\"data.xlsx\"\r\n"
            ."Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"data.xlsx\"\r\n\r\n".$payload."\r\n"
            ."--m1--\r\n";

        $result = $this->handler->anonymizeEmlStructured(node: $this->emlNode($eml), entities: []);

        $this->assertCount(1, $result->attachments);
        $this->assertTrue($result->attachments[0]->unsupported);
        $this->assertNull($result->attachments[0]->redactedContent);

    }//end testUnsupportedAttachmentIsFlaggedAndDropped()


    /**
     * 4.4 — a nested message/rfc822 attachment recurses: its body is redacted
     * and carried as a nested AnonymisedEmlStructure (no flat bytes).
     *
     * @return void
     */
    public function testNestedEmlRecursesAndRedacts(): void
    {
        $inner = "From: inner@example.nl\r\nTo: x@example.nl\r\nSubject: Inner\r\n"
            ."Content-Type: text/plain\r\n\r\nInner BSN 111222333 here.\r\n";
        $eml   = "From: outer@example.nl\r\nTo: y@example.nl\r\nSubject: Outer\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"n1\"\r\n\r\n"
            ."--n1\r\nContent-Type: text/plain\r\n\r\nForwarded below.\r\n"
            ."--n1\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment; filename=\"forward.eml\"\r\n\r\n".$inner."\r\n"
            ."--n1--\r\n";

        $result = $this->handler->anonymizeEmlStructured(
            node: $this->emlNode($eml),
            entities: [['text' => '111222333', 'entityType' => 'SSN', 'key' => '1']]
        );

        $this->assertCount(1, $result->attachments);
        $nested = $result->attachments[0]->nestedEml;
        $this->assertInstanceOf(AnonymisedEmlStructure::class, $nested);
        $this->assertStringContainsString('[SSN: 1]', (string) $nested->body->plain);
        $this->assertStringNotContainsString('111222333', (string) $nested->body->plain);

    }//end testNestedEmlRecursesAndRedacts()


    /**
     * 4.6 — the same entity yields the same placeholder in body and headers.
     *
     * @return void
     */
    public function testPlaceholderConsistencyAcrossBodyAndHeaders(): void
    {
        $eml = "From: Jan de Vries <jan@example.nl>\r\nTo: r@example.nl\r\n"
            ."Subject: Re\r\nContent-Type: text/plain\r\n\r\nGroet aan jan@example.nl\r\n";

        $result = $this->handler->anonymizeEmlStructured(
            node: $this->emlNode($eml),
            entities: [['text' => 'jan@example.nl', 'entityType' => 'EMAIL', 'key' => '1']]
        );

        $this->assertStringContainsString('[EMAIL: 1]', (string) $result->headers['from']);
        $this->assertStringContainsString('[EMAIL: 1]', (string) $result->body->plain);

    }//end testPlaceholderConsistencyAcrossBodyAndHeaders()


    /**
     * 4.3 — attachment redactor routing: word formats → word, pdf → pdf,
     * text → text, everything else → null (unsupported).
     *
     * @return void
     */
    public function testResolveAttachmentRedactorRouting(): void
    {
        $resolve = new ReflectionMethod(DocumentProcessingHandler::class, 'resolveAttachmentRedactor');
        $resolve->setAccessible(true);

        $this->assertSame('word', $resolve->invoke($this->handler, 'application/octet-stream', 'docx'));
        $this->assertSame('word', $resolve->invoke($this->handler, 'application/vnd.oasis.opendocument.text', 'odt'));
        $this->assertSame('word', $resolve->invoke($this->handler, 'application/rtf', 'rtf'));
        $this->assertSame('pdf', $resolve->invoke($this->handler, 'application/pdf', 'pdf'));
        $this->assertSame('text', $resolve->invoke($this->handler, 'text/plain', 'txt'));
        $this->assertSame('text', $resolve->invoke($this->handler, 'text/html', 'html'));
        $this->assertNull($resolve->invoke($this->handler, 'application/vnd.ms-excel', 'xlsx'));
        $this->assertNull($resolve->invoke($this->handler, 'image/png', 'png'));

    }//end testResolveAttachmentRedactorRouting()


    /**
     * The EML guard: anonymizeDocument refuses message/rfc822 rather than
     * silently routing it through the leaky raw-text fallback.
     *
     * @return void
     */
    public function testAnonymizeDocumentThrowsOnEml(): void
    {
        $node = $this->createMock(File::class);
        $node->method('getName')->willReturn('message.eml');
        $node->method('getMimeType')->willReturn('message/rfc822');

        $this->expectException(\Exception::class);
        $this->handler->anonymizeDocument(node: $node, entities: []);

    }//end testAnonymizeDocumentThrowsOnEml()


}//end class
