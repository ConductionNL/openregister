<?php

/**
 * Tests for SipPart.
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

use OCA\OpenRegister\Service\Edepot\SipPart;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\SipPart
 */
class SipPartTest extends TestCase {

	public function testOnlyASplitTransferCarriesASuffix(): void {
		$single = new SipPart(transferId: 't', sequenceNumber: 1, totalPackages: 1, format: 'zip');
		$second = new SipPart(transferId: 't', sequenceNumber: 2, totalPackages: 3, format: 'bagit');

		$this->assertFalse($single->isSplit());
		$this->assertSame('', $single->suffix());
		$this->assertTrue($second->isSplit());
		$this->assertSame('-part2', $second->suffix());
		$this->assertSame(['transferId' => 't', 'sequenceNumber' => 2, 'totalPackages' => 3], json_decode($second->sequenceJson(), true));
	}
}//end class
