<?php

/**
 * The OpenAnonymiser password is stored as a secret and never read back.
 *
 * The file settings are one JSON blob that the settings screen reads back in
 * full. A password in that blob would be returned in plain text, so it lives
 * on its own app config key, marked sensitive, and a settings read only says
 * whether one is set.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 */
class FileSettingsOpenAnonymiserPasswordTest extends TestCase {

	/**
	 * The stored app config, by key.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Keys written with the sensitive flag.
	 *
	 * @var array<int, string>
	 */
	private array $sensitive = [];

	/**
	 * A handler over an in-memory app config.
	 *
	 * @return FileSettingsHandler The handler.
	 */
	private function handler(): FileSettingsHandler {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->store[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->store[$key] = $value;
				if ($sensitive === true) {
					$this->sensitive[] = $key;
				}

				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->store[$key]);
			}
		);

		return new FileSettingsHandler($config, 'openregister');

	}//end handler()

	/**
	 * THE DEFECT: a saved password is stored as a secret and never read back.
	 *
	 * @return void
	 */
	public function testThePasswordIsStoredAsASecretAndNeverReadBack(): void {
		$handler = $this->handler();

		$written = $handler->updateFileSettingsOnly(
			[
				'openAnonymiserSource' => 'external',
				'openAnonymiserUsername' => 'openregister',
				'openAnonymiserPassword' => 'top-secret-value',
			]
		);
		$read = $handler->getFileSettingsOnly();

		$this->assertSame('top-secret-value', $handler->getOpenAnonymiserPassword());
		$this->assertContains(FileSettingsHandler::OPENANONYMISER_PASSWORD_KEY, $this->sensitive);
		$this->assertStringNotContainsString('top-secret-value', $this->store['fileManagement']);
		$this->assertStringNotContainsString('top-secret-value', json_encode($written));
		$this->assertStringNotContainsString('top-secret-value', json_encode($read));
		$this->assertSame('openregister', $read['openAnonymiserUsername']);
		$this->assertTrue($read['openAnonymiserPasswordSet']);
		$this->assertTrue($written['openAnonymiserPasswordSet']);

	}//end testThePasswordIsStoredAsASecretAndNeverReadBack()

	/**
	 * A save without the password key keeps it; an empty string clears it.
	 *
	 * @return void
	 */
	public function testASaveWithoutThePasswordKeepsItAndAnEmptyOneClearsIt(): void {
		$handler = $this->handler();
		$handler->updateFileSettingsOnly(['openAnonymiserUsername' => 'openregister', 'openAnonymiserPassword' => 'kept']);

		$handler->updateFileSettingsOnly(['openAnonymiserUsername' => 'openregister']);
		$this->assertSame('kept', $handler->getOpenAnonymiserPassword());
		$this->assertTrue($handler->getFileSettingsOnly()['openAnonymiserPasswordSet']);

		$handler->updateFileSettingsOnly(['openAnonymiserUsername' => '', 'openAnonymiserPassword' => '']);
		$this->assertSame('', $handler->getOpenAnonymiserPassword());
		$this->assertFalse($handler->getFileSettingsOnly()['openAnonymiserPasswordSet']);

	}//end testASaveWithoutThePasswordKeepsItAndAnEmptyOneClearsIt()

	/**
	 * A fresh install reads no user name and no password.
	 *
	 * @return void
	 */
	public function testAFreshInstallHasNoCredentials(): void {
		$read = $this->handler()->getFileSettingsOnly();

		$this->assertSame('', $read['openAnonymiserUsername']);
		$this->assertFalse($read['openAnonymiserPasswordSet']);

	}//end testAFreshInstallHasNoCredentials()
}//end class
