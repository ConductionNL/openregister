<?php

/**
 * The Tables reader is unavailable, not failing, when the app manager
 * cannot say whether Tables is enabled.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Availability tests for {@see TablesTableReader}.
 */
class TablesTableReaderAvailabilityTest extends TestCase {

	/**
	 * A failing app manager reads as unavailable and nothing is resolved
	 * from the container; a disabled app reads as unavailable too.
	 *
	 * @return void
	 */
	public function testAnUnanswerableAppManagerMeansUnavailable(): void {
		$failing = $this->createMock(IAppManager::class);
		$failing->method('isEnabledForUser')->with('tables')->willThrowException(new RuntimeException('no session'));
		$disabled = $this->createMock(IAppManager::class);
		$disabled->method('isEnabledForUser')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertFalse((new TablesTableReader(app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $failing, container: $container, logger: new NullLogger())))->isAvailable());
		$this->assertFalse((new TablesTableReader(app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $disabled, container: $container, logger: new NullLogger())))->isAvailable());
	}//end testAnUnanswerableAppManagerMeansUnavailable()
}//end class
