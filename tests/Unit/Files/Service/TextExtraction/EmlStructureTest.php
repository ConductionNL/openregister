<?php

/**
 * Serialisation of the parsed EML structure.
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

use OCA\OpenRegister\Files\Service\TextExtraction\EmlBody;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure;
use PHPUnit\Framework\TestCase;

class EmlStructureTest extends TestCase {

	public function testJsonCarriesHeadersBodyAndAttachments(): void {
		$structure = new EmlStructure(['subject' => 'Hi'], new EmlBody('plain', null), []);

		$this->assertSame(
			'{"headers":{"subject":"Hi"},"body":{"plainText":"plain","html":null},"attachments":[]}',
			json_encode($structure)
		);
	}//end testJsonCarriesHeadersBodyAndAttachments()
}//end class
