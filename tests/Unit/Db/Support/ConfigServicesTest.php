<?php

/**
 * ConfigServices unit test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Support;

use OCA\OpenRegister\Db\Support\ConfigServices;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Support\ConfigServices
 */
final class ConfigServicesTest extends TestCase {
	/**
	 * The bundle hands back exactly the services it was built with.
	 *
	 * @return void
	 */
	public function testItHandsBackTheServicesItWasBuiltWith(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$config = $this->createMock(IConfig::class);

		$bundle = new ConfigServices($appConfig, $config);

		$this->assertSame($appConfig, $bundle->appConfig);
		$this->assertSame($config, $bundle->config);
	}//end testItHandsBackTheServicesItWasBuiltWith()
}//end class
