<?php

/**
 * Behaviour tests for ExampleSetConfigStore: typed reads and writes of app config,
 * and how failures are absorbed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigKey;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigStore;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigValue;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the typed app-config reads and writes of an example set.
 */
class ExampleSetConfigStoreBehaviourTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
	}//end setUp()

	private function store(): ExampleSetConfigStore {
		return new ExampleSetConfigStore($this->appConfig);
	}//end store()

	public function testHasFollowsTheAppConfigAndAFailureIsAbsent(): void {
		$this->appConfig->method('hasKey')->willReturnCallback(
			static function (string $app, string $key): bool {
				if ($key === 'broken') {
					throw new \RuntimeException('db');
				}

				return $app === 'alpha' && $key === 'there';
			}
		);

		$this->assertTrue($this->store()->has('alpha', 'there'));
		$this->assertFalse($this->store()->has('alpha', 'missing'));
		$this->assertFalse($this->store()->has('alpha', 'broken'));
	}

	public function testReadsAStringValue(): void {
		$this->appConfig->method('getValueString')->with('alpha', 'theme', '')->willReturn('dark');
		$this->appConfig->expects($this->never())->method('getValueInt');

		$this->assertSame('dark', $this->store()->read(new ExampleSetConfigValue(new ExampleSetConfigKey('alpha', 'theme'))));
	}

	public function testReadsAnExistingIntAsText(): void {
		$this->appConfig->method('hasKey')->willReturn(true);
		$this->appConfig->method('getValueInt')->with('alpha', 'max')->willReturn(42);

		$this->assertSame('42', $this->store()->read(new ExampleSetConfigValue(new ExampleSetConfigKey('alpha', 'max'), '', 'int')));
	}

	public function testAnAbsentIntReadsAsEmptyNotZero(): void {
		$this->appConfig->method('hasKey')->willReturn(false);
		$this->appConfig->expects($this->never())->method('getValueInt');

		$this->assertSame('', $this->store()->read(new ExampleSetConfigValue(new ExampleSetConfigKey('alpha', 'max'), '', 'int')));
	}

	public function testAFailedReadIsEmpty(): void {
		$this->appConfig->method('getValueString')->willThrowException(new \RuntimeException('type conflict'));

		$this->assertSame('', $this->store()->read(new ExampleSetConfigValue(new ExampleSetConfigKey('alpha', 'theme'))));
	}

	public function testWritesAnIntAsAnInt(): void {
		$this->appConfig->expects($this->once())->method('setValueInt')->with('alpha', 'max', 7);
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->store()->write(new ExampleSetConfigValue(new ExampleSetConfigKey('alpha', 'max'), '7', 'int'));
	}

	public function testWritesAStringAsAString(): void {
		$this->appConfig->expects($this->once())->method('setValueString')->with('alpha', 'theme', 'dark');
		$this->appConfig->expects($this->never())->method('setValueInt');

		$this->store()->write(new ExampleSetConfigValue(new ExampleSetConfigKey('alpha', 'theme'), 'dark'));
	}

	public function testTryWriteReportsSuccessAndFailure(): void {
		$this->appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value): bool {
				if ($key === 'locked') {
					throw new \RuntimeException('type conflict');
				}

				return true;
			}
		);

		$this->assertTrue($this->store()->tryWrite(new ExampleSetConfigValue(new ExampleSetConfigKey('alpha', 'theme'), 'dark')));
		$this->assertFalse($this->store()->tryWrite(new ExampleSetConfigValue(new ExampleSetConfigKey('alpha', 'locked'), 'x')));
	}
}//end class
