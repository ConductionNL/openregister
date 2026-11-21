<?php

/**
 * RelsPruneTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Files\Service\File\Sanitizer\RelsPrune}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File\Sanitizer;

use DOMDocument;
use OCA\OpenRegister\Files\Service\File\Sanitizer\RelsPrune;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see RelsPrune}.
 */
class RelsPruneTest extends TestCase {
	private const HL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

	private const IMG = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

	/**
	 * A rels DOM with one comments target, one hyperlink and one image.
	 *
	 * @return DOMDocument
	 */
	private function rels(): DOMDocument {
		$dom = new DOMDocument();
		$dom->loadXML(
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="x/comments" Target="comments.xml"/>'
			. '<Relationship Id="rId2" Type="' . self::HL . '" Target="https://example.org/" TargetMode="External"/>'
			. '<Relationship Id="rId3" Type="' . self::IMG . '" Target="media/image1.png"/>'
			. '</Relationships>'
		);
		return $dom;
	}//end rels()

	/**
	 * Ids left in a rels DOM.
	 *
	 * @param DOMDocument $dom The DOM.
	 *
	 * @return string[]
	 */
	private function ids(DOMDocument $dom): array {
		$ids = [];
		foreach ($dom->getElementsByTagName('Relationship') as $rel) {
			$ids[] = $rel->getAttribute('Id');
		}
		return $ids;
	}//end ids()

	/**
	 * Drops by target basename and by hyperlink id, keeps the rest.
	 *
	 * @return void
	 */
	public function testDropsByTargetAndHyperlinkId(): void {
		$dom = $this->rels();
		$prune = new RelsPrune(relsName: 'word/_rels/document.xml.rels', targetBasenames: ['comments.xml'], hyperlinkIds: ['rId2']);

		$this->assertFalse($prune->isEmpty());
		$this->assertTrue($prune->applyTo(dom: $dom));
		$this->assertSame(['rId3'], $this->ids(dom: $dom));
	}//end testDropsByTargetAndHyperlinkId()

	/**
	 * A matching id on a non-hyperlink relationship is kept.
	 *
	 * @return void
	 */
	public function testIdOnNonHyperlinkIsKept(): void {
		$dom = $this->rels();
		$prune = new RelsPrune(relsName: 'word/_rels/header1.xml.rels', targetBasenames: [], hyperlinkIds: ['rId3']);

		$this->assertFalse($prune->applyTo(dom: $dom));
		$this->assertSame(['rId1', 'rId2', 'rId3'], $this->ids(dom: $dom));
	}//end testIdOnNonHyperlinkIsKept()

	/**
	 * Nothing to drop is empty.
	 *
	 * @return void
	 */
	public function testEmpty(): void {
		$this->assertTrue((new RelsPrune(relsName: 'a.rels', targetBasenames: [], hyperlinkIds: []))->isEmpty());
	}//end testEmpty()
}//end class
