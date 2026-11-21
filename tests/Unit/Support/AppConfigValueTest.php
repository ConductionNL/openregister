<?php

/**
 * A non-empty, trimmed OpenRegister app-config value, or null when it is
 * blank or reading it throws.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Support\AppConfigValue;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AppConfigValue.
 *
 * @covers \OCA\OpenRegister\Support\AppConfigValue
 */
class AppConfigValueTest extends TestCase {

	private function config(string $value): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueString')->with('openregister', 'some_key', '')->willReturn($value);

		return $config;
	}//end config()

	public function testAValueIsReturnedTrimmed(): void {
		$this->assertSame('https://x.example', AppConfigValue::nonEmpty($this->config('  https://x.example '), 'some_key'));
	}//end testAValueIsReturnedTrimmed()

	public function testABlankValueIsNull(): void {
		$this->assertNull(AppConfigValue::nonEmpty($this->config(''), 'some_key'));
		$this->assertNull(AppConfigValue::nonEmpty($this->config("  \t"), 'some_key'));
	}//end testABlankValueIsNull()

	public function testAReadThatThrowsIsNull(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willThrowException(new RuntimeException('wrong type'));

		$this->assertNull(AppConfigValue::nonEmpty($config, 'some_key'));
	}//end testAReadThatThrowsIsNull()
}//end class
