<?php

/**
 * Tests for the typed app config reads and writes of an example set.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigStore;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigKey;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigValue;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigStore
 */
class ExampleSetConfigStoreTest extends TestCase {

	public function testAnIntegerKeyIsReadAsTextAndAMissingOneAsEmpty(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturnCallback(static fn (string $app, string $key): bool => $key === 'n');
		$config->method('getValueInt')->willReturn(7);
		$store = new ExampleSetConfigStore(appConfig: $config);

		$this->assertSame('7', $store->read(target: new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: 'a', key: 'n'), type: 'int')));
		$this->assertSame('', $store->read(target: new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: 'a', key: 'gone'), type: 'int')));
	}

	public function testAFailingReadIsEmptyAndAFailingHasIsFalse(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willThrowException(new RuntimeException('down'));
		$config->method('getValueString')->willThrowException(new RuntimeException('down'));
		$store = new ExampleSetConfigStore(appConfig: $config);

		$this->assertFalse($store->has(app: 'a', key: 'k'));
		$this->assertSame('', $store->read(target: new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: 'a', key: 'k'))));
	}

	public function testAValueIsWrittenWithItsType(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueInt')->with('a', 'n', 8);
		$config->expects($this->once())->method('setValueString')->with('a', 'k', 'v');
		$store = new ExampleSetConfigStore(appConfig: $config);

		$store->write(target: new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: 'a', key: 'n'), value: '8', type: 'int'));
		$store->write(target: new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: 'a', key: 'k'), value: 'v'));
	}

	public function testTryWriteReportsAFailure(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willThrowException(new RuntimeException('down'));
		$store = new ExampleSetConfigStore(appConfig: $config);

		$this->assertFalse($store->tryWrite(target: new ExampleSetConfigValue(at: new ExampleSetConfigKey(app: 'a', key: 'k'), value: 'v')));
	}
}//end class
