<?php

/**
 * Golden test for ConfigurationSettingsHandler::getVersionInfoOnly().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsSupport;
use OCA\OpenRegister\Service\Settings\SettingsDirectory;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The version info, read and defaulted, and its failure.
 */
class ConfigurationVersionInfoGoldenTest extends TestCase {

	/**
	 * A handler whose app manager answers this app info, or throws.
	 *
	 * @param array<string, mixed>|null|RuntimeException $appInfo The answer.
	 *
	 * @return ConfigurationSettingsHandler The handler.
	 */
	private function handler(array|null|RuntimeException $appInfo): ConfigurationSettingsHandler {
		$appManager = $this->createMock(IAppManager::class);
		if ($appInfo instanceof RuntimeException) {
			$appManager->method('getAppInfo')->willThrowException($appInfo);
		} else {
			$appManager->method('getAppInfo')->with('openregister')->willReturn($appInfo);
		}

		return new ConfigurationSettingsHandler(
			$this->createMock(IAppConfig::class),
			new SettingsDirectory($this->createMock(IGroupManager::class), $this->createMock(IUserManager::class), $this->createMock(OrganisationMapper::class)),
			new ConfigurationSettingsSupport($this->createMock(LoggerInterface::class), $appManager)
		);
	}//end handler()

	public function testTheAppInfoIsReadAndDefaulted(): void {
		$info = $this->handler(['version' => '1.2.3', 'name' => null, 'author' => 'Team'])->getVersionInfoOnly();

		$this->assertSame(['version', 'name', 'description', 'author', 'licence', 'timestamp', 'date'], array_keys($info));
		$this->assertSame(['1.2.3', 'OpenRegister', '', 'Team', 'EUPL-1.2'], array_slice(array_values($info), 0, 5));
		$this->assertIsInt($info['timestamp']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $info['date']);

		$this->assertSame(['unknown', 'OpenRegister', '', 'Conduction', 'EUPL-1.2'], array_slice(array_values($this->handler(null)->getVersionInfoOnly()), 0, 5));
	}//end testTheAppInfoIsReadAndDefaulted()

	public function testAFailedReadSaysWhy(): void {
		$this->assertSame(
			['version' => 'unknown', 'error' => 'Failed to retrieve version info: no app'],
			$this->handler(new RuntimeException('no app'))->getVersionInfoOnly()
		);
	}//end testAFailedReadSaysWhy()
}//end class
