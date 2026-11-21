<?php

/**
 * RepairPlatform hands a repair step exactly the services it was built with, immutably.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair\Support;

use Error;
use OCA\OpenRegister\Repair\Support\RepairPlatform;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The parameter bundle a repair step receives.
 */
class RepairPlatformTest extends TestCase {

	public function testTheBundleCannotBeRepointedAfterConstruction(): void {
		$db = $this->createMock(IDBConnection::class);
		$platform = new RepairPlatform($db, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class));

		try {
			$platform->db = $this->createMock(IDBConnection::class);
			$this->fail('A repair step must not be able to swap the connection it was handed.');
		} catch (Error $e) {
			$this->assertStringContainsString('readonly', $e->getMessage());
		}

		$this->assertSame($db, $platform->db);
	}
}
