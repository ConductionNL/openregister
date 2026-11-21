<?php

/**
 * Behaviour tests for the setAppConfig failure branch of GenericStoreInstaller.
 *
 * A config write that throws must be reported per component as an error,
 * never thrown out of install(), and must not mark the item a success.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Store;

use OCA\OpenRegister\AppHost\Store\GenericStoreInstaller;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the config-write catch path of the installer.
 */
class GenericStoreInstallerBehaviourTest extends TestCase {

	/**
	 * A store that allows one config key.
	 *
	 * @return StoreManifest
	 */
	private function store(): StoreManifest {
		return StoreManifest::fromManifest(
			'integriq',
			['store' => ['schema' => 'catalog_item', 'configurable' => ['enableSoapAdapter']]]
		);
	}//end store()

	/**
	 * A config store that throws is reported as an error for that key, logged
	 * server-side, and the item is not a success.
	 *
	 * @return void
	 */
	public function testAFailingConfigWriteIsReportedAsAnErrorNotThrown(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('setValueString')
			->with('integriq', 'enableSoapAdapter', '42')
			->willThrowException(new RuntimeException('db gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->callback(static function (string $message): bool {
				return str_contains($message, 'enableSoapAdapter')
					&& str_contains($message, 'integriq')
					&& str_contains($message, 'db gone');
			}));

		$installer = new GenericStoreInstaller(
			$this->createMock(ObjectOperationsInterface::class),
			$appConfig,
			$logger
		);

		$report = $installer->install(
			$this->store(),
			['components' => [['op' => 'setAppConfig', 'key' => 'enableSoapAdapter', 'value' => 42]]]
		);

		$this->assertFalse($report['success']);
		$this->assertSame(
			[[
				'schema' => 'enableSoapAdapter',
				'status' => 'error',
				'message' => 'The setting could not be written.',
			]],
			$report['components']
		);
	}//end testAFailingConfigWriteIsReportedAsAnErrorNotThrown()

	/**
	 * Paired allow path: the same write that succeeds is installed and logs nothing.
	 *
	 * @return void
	 */
	public function testASucceedingConfigWriteIsInstalledAndLogsNothing(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('setValueString')
			->with('integriq', 'enableSoapAdapter', '42')
			->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$installer = new GenericStoreInstaller(
			$this->createMock(ObjectOperationsInterface::class),
			$appConfig,
			$logger
		);

		$report = $installer->install(
			$this->store(),
			['components' => [['op' => 'setAppConfig', 'key' => 'enableSoapAdapter', 'value' => 42]]]
		);

		$this->assertTrue($report['success']);
		$this->assertSame('installed', $report['components'][0]['status']);
	}//end testASucceedingConfigWriteIsInstalledAndLogsNothing()
}//end class
