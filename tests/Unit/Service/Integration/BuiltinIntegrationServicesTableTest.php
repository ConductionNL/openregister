<?php

/**
 * BuiltinIntegrationServicesTableTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Integration
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\BuiltinIntegrationServices;
use PHPUnit\Framework\TestCase;

/**
 * Pins the built-in wiring table: every service, every argument, every bundle and
 * their order, so naming the shared bundles cannot change what gets built.
 */
class BuiltinIntegrationServicesTableTest extends TestCase {

	public function testWiringTableIsUnchanged(): void {
		$encoded = json_encode(BuiltinIntegrationServices::FACTORIES, JSON_THROW_ON_ERROR);

		$this->assertSame('6825facdf1148ae6809f2721274a58ee0e040987cf3ecd3b80b08181447dfbdd', hash('sha256', $encoded));
	}

	public function testConnectorProvidersShareOneRoutingBundle(): void {
		$factories = BuiltinIntegrationServices::FACTORIES;
		$routing   = $factories[\OCA\OpenRegister\Service\Integration\Providers\KvkProvider::class]['routing'];

		$this->assertSame(\OCA\OpenRegister\Service\Integration\Providers\ConnectorRouting::class, $routing[0]);
		$this->assertSame(['router', 'appManager', 'l10n'], array_keys($routing[1]));
		$this->assertSame($routing, $factories[\OCA\OpenRegister\Service\Integration\Providers\XwikiProvider::class]['routing']);
		$this->assertSame($routing, $factories[\OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider::class]['routing']);
	}
}//end class
