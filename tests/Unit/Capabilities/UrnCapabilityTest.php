<?php

/**
 * Tests for UrnCapability: the exact capabilities block, with the instance slug read from the URN service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use OCA\OpenRegister\Capabilities\UrnCapability;
use OCA\OpenRegister\Service\UrnServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * UrnCapability over a mocked URN service.
 */
class UrnCapabilityTest extends TestCase {

	public function testTheCapabilityBlockCarriesTheConfiguredInstanceSlug(): void {
		$urn = $this->createMock(UrnServiceInterface::class);
		$urn->expects($this->once())->method('getInstanceSlug')->willReturn('gemeente-x');

		$this->assertSame(
			[
				'openregister' => [
					'urn' => [
						'enabled'   => true,
						'version'   => '1',
						'nid'       => 'nl-or',
						'instance'  => 'gemeente-x',
						'endpoints' => [
							'resolve' => '/apps/openregister/api/urn/resolve',
							'lookup'  => '/apps/openregister/api/urn/lookup',
							'bulk'    => '/apps/openregister/api/urn/bulk',
						],
						'features'  => [
							'bulkResolve'   => true,
							'reverseLookup' => true,
							'crossInstance' => false,
							'aliases'       => false,
							'versioning'    => false,
						],
					],
				],
			],
			(new UrnCapability($urn))->getCapabilities()
		);
	}
}
