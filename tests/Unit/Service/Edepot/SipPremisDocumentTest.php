<?php

/**
 * Tests for SipPremisDocument.
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

use OCA\OpenRegister\Service\Edepot\SipPremisDocument;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\SipPremisDocument
 * @uses \OCA\OpenRegister\Service\Edepot\PremisIdentifier
 * @uses \OCA\OpenRegister\Service\Edepot\SipLayout
 */
class SipPremisDocumentTest extends TestCase {

	public function testOneFileObjectPerContentFile(): void {
		$premis = new SipPremisDocument(transferId: 't1');
		$premis->addFile(uuid: 'u', file: ['name' => 'a & b.pdf', 'size' => 3, 'format' => 'application/pdf', 'checksum' => 'c1', 'isRendition' => false]);

		$xml = new \SimpleXMLElement($premis->xml());
		$xml->registerXPathNamespace('premis', 'info:lc/xmlns/premis-v2');
		$this->assertSame('t1', (string)$xml->xpath('//premis:eventIdentifierValue')[0]);
		$this->assertSame('creation', (string)$xml->xpath('//premis:eventType')[0]);
		$this->assertSame('objects/u/content/original/a & b.pdf', (string)$xml->xpath('//premis:objectIdentifierValue')[0]);
		$this->assertSame('c1', (string)$xml->xpath('//premis:messageDigest')[0]);
		$this->assertSame('3', (string)$xml->xpath('//premis:size')[0]);
		$this->assertSame('application/pdf', (string)$xml->xpath('//premis:formatName')[0]);
	}
}//end class
