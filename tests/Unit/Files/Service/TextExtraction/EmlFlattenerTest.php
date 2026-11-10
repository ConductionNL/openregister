<?php

/**
 * Plain-text flattening of a parsed EML structure.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\EmlAttachment;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlAttachmentFile;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlBody;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlFlattener;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure;
use PHPUnit\Framework\TestCase;

class EmlFlattenerTest extends TestCase {

	private function flattener(): EmlFlattener {
		return new EmlFlattener(static fn (string $value): string => '[' . $value . ']');
	}//end flattener()

	public function testHeadersInFixedOrderSkippingEmptyOnesThenPlainBody(): void {
		$structure = new EmlStructure(
			['subject' => 'Hello', 'from' => 'a@example.com', 'cc' => '', 'to' => null, 'messageId' => 'x'],
			new EmlBody('body text', '<p>ignored</p>'),
			[]
		);

		$this->assertSame("From: a@example.com\nSubject: Hello\n\n[body text]", $this->flattener()->flatten($structure));
	}//end testHeadersInFixedOrderSkippingEmptyOnesThenPlainBody()

	public function testHtmlBodyIsUsedWhenPlainIsEmptyAndStripsMarkup(): void {
		$html = "<style>p{}</style><script>x()</script><h1>Title</h1><p>A\t\t&amp; B</p><br><br><br><br><div>End</div>";
		$structure = new EmlStructure([], new EmlBody('', $html), []);

		$this->assertSame("\n[\nTitle\n\nA & B\n\nEnd\n]", $this->flattener()->flatten($structure));
	}//end testHtmlBodyIsUsedWhenPlainIsEmptyAndStripsMarkup()

	public function testNoBodyAndNoHeadersFlattensToEmpty(): void {
		$structure = new EmlStructure([], new EmlBody(null, null), []);

		$this->assertSame('', $this->flattener()->flatten($structure));
	}//end testNoBodyAndNoHeadersFlattensToEmpty()

	public function testAttachmentsAreMarkedAndNestedMessagesFlattenedInline(): void {
		$nested = new EmlStructure(['subject' => 'Inner'], new EmlBody('inner body', null), []);
		$structure = new EmlStructure(
			['subject' => 'Outer'],
			new EmlBody(null, ''),
			[
				new EmlAttachment(new EmlAttachmentFile('a.pdf', 'application/pdf', '%PDF'), false, null, null),
				new EmlAttachment(new EmlAttachmentFile('fwd.eml', 'message/rfc822', ''), false, null, $nested),
			]
		);

		$this->assertSame(
			"Subject: Outer\n\n--- Attachment: a.pdf (application/pdf, not extractable inline in v1) ---\n\n"
			. "--- Attachment: fwd.eml ---\nSubject: Inner\n\n[inner body]",
			$this->flattener()->flatten($structure)
		);
	}//end testAttachmentsAreMarkedAndNestedMessagesFlattenedInline()
}//end class
