<?php

/**
 * EmlParser reads the reply-threading headers into the structure.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * REQ-RTH-010: `In-Reply-To` and `References` are read into the structure
 * with their angle brackets stripped, matched case-insensitively by header
 * name, and absent when the message carries no such header.
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\EmlParser
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\EmlBody
 */
final class EmlThreadHeadersTest extends TestCase {

	/**
	 * The parser under test.
	 *
	 * @var EmlParser
	 */
	private EmlParser $parser;

	/**
	 * Build the parser.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->parser = new EmlParser(logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end setUp()

	/**
	 * Parse an EML string into a structure.
	 *
	 * @param string $eml The message source.
	 *
	 * @return EmlStructure
	 */
	private function structureFrom(string $eml): EmlStructure {
		return $this->parser->parseMessage(message: (new MailMimeParser())->parse($eml, false));
	}//end structureFrom()

	/**
	 * The one answered id and the whole ancestry are read, brackets stripped, in order.
	 *
	 * @return void
	 */
	public function testInReplyToAndReferencesAreRead(): void {
		$eml = "From: Alice <alice@example.com>\r\n"
			. "Subject: Re: uw verzoek\r\n"
			. "Message-ID: <reply@gemeente.nl>\r\n"
			. "In-Reply-To: <a1@gemeente.nl>\r\n"
			. "References: <a0@gemeente.nl> <a1@gemeente.nl>\r\n"
			. "\r\nBody.\r\n";

		$structure = $this->structureFrom(eml: $eml);

		$this->assertSame(expected: 'a1@gemeente.nl', actual: $structure->headers['inReplyTo']);
		$this->assertSame(expected: ['a0@gemeente.nl', 'a1@gemeente.nl'], actual: $structure->headers['references']);
	}//end testInReplyToAndReferencesAreRead()

	/**
	 * A client writing the header names in lower case still threads.
	 *
	 * @return void
	 */
	public function testHeaderNamesAreCaseInsensitive(): void {
		$eml = "From: Alice <alice@example.com>\r\n"
			. "subject: hi\r\n"
			. "in-reply-to: <a1@gemeente.nl>\r\n"
			. "REFERENCES: <a1@gemeente.nl>\r\n"
			. "\r\nBody.\r\n";

		$structure = $this->structureFrom(eml: $eml);

		$this->assertSame(expected: 'a1@gemeente.nl', actual: $structure->headers['inReplyTo']);
		$this->assertSame(expected: ['a1@gemeente.nl'], actual: $structure->headers['references']);
	}//end testHeaderNamesAreCaseInsensitive()

	/**
	 * A message with no threading headers carries an empty reply and references.
	 *
	 * @return void
	 */
	public function testAFirstMessageHasNoThreadHeaders(): void {
		$eml = "From: Alice <alice@example.com>\r\n"
			. "Subject: start\r\n"
			. "Message-ID: <first@gemeente.nl>\r\n"
			. "\r\nBody.\r\n";

		$structure = $this->structureFrom(eml: $eml);

		$this->assertSame(expected: '', actual: $structure->headers['inReplyTo']);
		$this->assertSame(expected: [], actual: $structure->headers['references']);
	}//end testAFirstMessageHasNoThreadHeaders()
}//end class
