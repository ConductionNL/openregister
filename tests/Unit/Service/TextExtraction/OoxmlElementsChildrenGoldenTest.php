<?php

/**
 * Characterisation tests for OoxmlElements::children().
 *
 * Pins that a missing parent has no children, and that only direct element
 * children with the asked local name come back, in document order (text
 * nodes, other names and grandchildren are not returned).
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
 * @spec openspec/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use DOMDocument;
use OCA\OpenRegister\Files\Service\TextExtraction\OoxmlElements;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for children().
 */
class OoxmlElementsChildrenGoldenTest extends TestCase {

	/**
	 * Only direct children with the asked local name, in order.
	 *
	 * @return void
	 */
	public function testChildrenByLocalName(): void {
		$doc = new DOMDocument();
		$doc->loadXML('<w:body xmlns:w="urn:w"><w:p id="1"/>text<w:tbl><w:p id="nested"/></w:tbl><w:p id="2"/><p id="unprefixed"/></w:body>');
		$elements = new OoxmlElements();

		$ids = array_map(
			static fn (\DOMElement $element): string => $element->getAttribute('id'),
			$elements->children($doc->documentElement, 'p')
		);

		$this->assertSame(['1', '2', 'unprefixed'], $ids);
		$this->assertSame([], $elements->children($doc->documentElement, 'r'));
		$this->assertSame([], $elements->children(null, 'p'));
	}//end testChildrenByLocalName()
}//end class
