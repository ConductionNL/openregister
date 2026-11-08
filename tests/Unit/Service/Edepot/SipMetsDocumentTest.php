<?php

/**
 * Tests for SipMetsDocument.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\SipMetsDocument;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\SipMetsDocument
 */
class SipMetsDocumentTest extends TestCase {

	public function testContentAndMetadataFilesShareOneCounter(): void {
		$mets = new SipMetsDocument(transferId: 't1');
		$mets->addObject(
			uuid: 'u',
			files: [['name' => 'a.pdf', 'size' => 3, 'format' => 'application/pdf', 'checksum' => 'c1', 'isRendition' => true]],
			metadata: [['path' => 'objects/u/mdto.xml', 'size' => 4, 'checksum' => 'c2']]
		);

		$xml = new \SimpleXMLElement($mets->xml());
		$xml->registerXPathNamespace('mets', 'http://www.loc.gov/METS/');
		$this->assertSame('t1', (string)$xml['OBJID']);
		$this->assertSame('FILE-1', (string)$xml->xpath('//mets:fileGrp[@USE="RENDITION"]/mets:file')[0]['ID']);
		$this->assertSame('MD-2', (string)$xml->xpath('//mets:fileGrp[@USE="METADATA"]/mets:file')[0]['ID']);
		$this->assertSame([], $xml->xpath('//mets:fileGrp[@USE="ORIGINAL"]/mets:file'));
		$this->assertCount(2, $xml->xpath('//mets:div[@LABEL="u"]/mets:fptr'));
		$this->assertSame('SIP-t1', (string)$xml->xpath('//mets:structMap/mets:div')[0]['LABEL']);
	}
}//end class
