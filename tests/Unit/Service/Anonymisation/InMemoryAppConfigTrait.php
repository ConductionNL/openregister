<?php

/**
 * An IAppConfig mock whose string values live in an array, for the
 * anonymisation detector version tests.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Keeps string app config values in $this->configValues.
 */
trait InMemoryAppConfigTrait {

	/**
	 * Stored values by "app/key".
	 *
	 * @var array<string, string>
	 */
	private array $configValues = [];

	/**
	 * How many string values were written.
	 *
	 * @var int
	 */
	private int $configWrites = 0;

	/**
	 * An app config whose string values read back what was written.
	 *
	 * @return IAppConfig&MockObject
	 */
	private function inMemoryAppConfig(): IAppConfig&MockObject {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->configValues[$app . '/' . $key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->configValues[$app . '/' . $key] = $value;
				$this->configWrites++;
				return true;
			}
		);
		$config->method('getValueInt')->willReturn(60);

		return $config;
	}//end inMemoryAppConfig()
}//end trait
