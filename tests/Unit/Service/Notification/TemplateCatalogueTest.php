<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCA\OpenRegister\Service\Notification\TemplateCatalogue;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * A catalogue declares the inventory and texts; null for either keeps the platform's own.
 *
 * @covers \OCA\OpenRegister\Service\Notification\TemplateCatalogue
 * @covers \OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry
 */
class TemplateCatalogueTest extends TestCase {

	/**
	 * A config holding no edits.
	 *
	 * @return IConfig
	 */
	private function config(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return $config;
	}

	public function testAnEmptyCatalogueKeepsThePlatformsOwn(): void {
		$config = $this->config();
		$catalogue = new TemplateCatalogue();

		$this->assertNull($catalogue->events);
		$this->assertNull($catalogue->shipped);
		$this->assertSame(
			(new NotificationTemplateRegistry($config))->listAll(),
			(new NotificationTemplateRegistry($config, $catalogue))->listAll()
		);
	}

	public function testADeclaredInventoryWithoutTextsIsAGap(): void {
		$registry = new NotificationTemplateRegistry(
			$this->config(),
			new TemplateCatalogue(['nobody_wrote_this' => ['group' => 'test', 'variables' => []]], [])
		);

		$this->assertSame(['nobody_wrote_this'], $registry->gaps());
	}
}//end class
