<?php

/**
 * Unit test for MdtoDocumentWriter's guards: a document holds only an
 * informatieobject or a bestand, and an element is never added to a node
 * that belongs to no document.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use DOMElement;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Edepot\MdtoDocumentWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\MdtoDocumentWriter
 */
class MdtoDocumentWriterTest extends TestCase {

	/**
	 * Both kinds make a document whose content element is that kind; anything else is refused.
	 *
	 * @return void
	 */
	public function testOnlyTheTwoKindsMakeADocument(): void {
		$writer = new MdtoDocumentWriter();
		foreach (['informatieobject', 'bestand'] as $kind) {
			[$dom, $content] = $writer->createDocument(kind: $kind);
			$this->assertSame($kind, $content->localName);
			$this->assertSame($dom, $content->ownerDocument);
			$this->assertStringContainsString(':'.$kind.'/>', $writer->serialise(dom: $dom));
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An MDTO document holds an informatieobject or a bestand, not dossier');
		$writer->createDocument(kind: 'dossier');
	}//end testOnlyTheTwoKindsMakeADocument()

	/**
	 * An element is added in the MDTO namespace under its parent; a detached parent is refused.
	 *
	 * @return void
	 */
	public function testAnElementNeedsAnAttachedParent(): void {
		$writer           = new MdtoDocumentWriter();
		[$dom, $content]  = $writer->createDocument(kind: 'bestand');
		$child            = $writer->element(parent: $content, name: 'naam');
		$this->assertSame($content, $child->parentNode);
		$this->assertSame($content->namespaceURI, $child->namespaceURI);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot add an MDTO element to a detached node');
		$writer->element(parent: new DOMElement('loose'), name: 'naam');
	}//end testAnElementNeedsAnAttachedParent()
}//end class
