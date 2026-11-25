<?php

/**
 * EmlParser over raw message bytes: the reply-threading headers, a References
 * header without any message id, an inline part whose Content-ID is empty and
 * a forwarded message attachment without content.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\AddressListSplitter;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlAttachment;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlAttachmentFile;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlAttachmentPart;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlBody;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlHeaderParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(EmlParser::class)]
#[UsesClass(AddressListSplitter::class)]
#[UsesClass(EmlAttachment::class)]
#[UsesClass(EmlAttachmentFile::class)]
#[UsesClass(EmlAttachmentPart::class)]
#[UsesClass(EmlBody::class)]
#[UsesClass(EmlHeaderParser::class)]
#[UsesClass(EmlStructure::class)]
class EmlParserBytesTest extends TestCase {

	/** @var EmlParser The subject under test. */
	private EmlParser $parser;

	protected function setUp(): void {
		$this->parser = new EmlParser(logger: new NullLogger());
	}//end setUp()

	public function testRawBytesYieldTheReplyThreadingHeaders(): void {
		$eml = "From: Ann <ann@example.org>\r\n"
			. "To: bob@example.org\r\n"
			. "Subject: Re: permit\r\n"
			. "Message-ID: <c3@example.org>\r\n"
			. "In-Reply-To: <b2@example.org>\r\n"
			. "References: <a1@example.org> <b2@example.org>\r\n"
			. "Content-Type: text/plain; charset=UTF-8\r\n"
			. "\r\n"
			. "Thanks for the reply.\r\n";

		$structure = $this->parser->parseBytes(bytes: $eml);

		$this->assertSame('Re: permit', $structure->headers['subject']);
		$this->assertSame('c3@example.org', $structure->headers['messageId']);
		$this->assertSame('b2@example.org', $structure->headers['inReplyTo']);
		$this->assertSame(['a1@example.org', 'b2@example.org'], $structure->headers['references']);
		$this->assertSame(['bob@example.org'], $structure->headers['to']);
		$this->assertSame("Thanks for the reply.", trim((string) $structure->body->plainText));
		$this->assertSame([], $structure->attachments);
	}//end testRawBytesYieldTheReplyThreadingHeaders()

	public function testReferencesWithoutAnyBracketedIdAndNoInReplyToAreEmpty(): void {
		$eml = "From: ann@example.org\r\nSubject: hello\r\nReferences: not-an-id\r\n\r\nbody\r\n";

		$structure = $this->parser->parseBytes(bytes: $eml);

		$this->assertSame([], $structure->headers['references']);
		$this->assertSame('', $structure->headers['inReplyTo']);
	}//end testReferencesWithoutAnyBracketedIdAndNoInReplyToAreEmpty()

	public function testAnInlinePartWithAnEmptyContentIdHasNoContentId(): void {
		$eml = "From: ann@example.org\r\n"
			. "Subject: logo\r\n"
			. "MIME-Version: 1.0\r\n"
			. "Content-Type: multipart/related; boundary=\"b1\"\r\n"
			. "\r\n"
			. "--b1\r\n"
			. "Content-Type: text/plain\r\n"
			. "\r\n"
			. "See the logo.\r\n"
			. "--b1\r\n"
			. "Content-Type: image/png; name=\"logo.png\"\r\n"
			. "Content-Disposition: inline; filename=\"logo.png\"\r\n"
			. "Content-ID: <>\r\n"
			. "\r\n"
			. "PNGDATA\r\n"
			. "--b1--\r\n";

		$structure = $this->parser->parseBytes(bytes: $eml);

		$this->assertCount(1, $structure->attachments);
		$this->assertSame('logo.png', $structure->attachments[0]->filename);
		$this->assertTrue($structure->attachments[0]->isInline);
		$this->assertNull($structure->attachments[0]->contentId);
	}//end testAnInlinePartWithAnEmptyContentIdHasNoContentId()

	public function testAForwardedMessageAttachmentWithoutContentHasNoNestedStructure(): void {
		$eml = "From: ann@example.org\r\n"
			. "Subject: fwd\r\n"
			. "MIME-Version: 1.0\r\n"
			. "Content-Type: multipart/mixed; boundary=\"b2\"\r\n"
			. "\r\n"
			. "--b2\r\n"
			. "Content-Type: text/plain\r\n"
			. "\r\n"
			. "Forwarded below.\r\n"
			. "--b2\r\n"
			. "Content-Type: message/rfc822; name=\"empty.eml\"\r\n"
			. "Content-Disposition: attachment; filename=\"empty.eml\"\r\n"
			. "\r\n"
			. "\r\n"
			. "--b2--\r\n";

		$structure = $this->parser->parseBytes(bytes: $eml);

		$this->assertCount(1, $structure->attachments);
		$this->assertSame('message/rfc822', $structure->attachments[0]->mimeType);
		$this->assertSame('', $structure->attachments[0]->content);
		$this->assertNull($structure->attachments[0]->nestedEml);
	}//end testAForwardedMessageAttachmentWithoutContentHasNoNestedStructure()
}//end class
