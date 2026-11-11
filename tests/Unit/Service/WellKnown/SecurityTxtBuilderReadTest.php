<?php

/**
 * How SecurityTxtBuilder reads its administered values: trimmed, blank as unset,
 * and an unreadable configuration as no file rather than an error.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\WellKnown
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\WellKnown;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilder
 */
class SecurityTxtBuilderReadTest extends TestCase {

	public function testValuesAreReadTrimmedFromThisAppAndBlankIsUnset(): void {
		$apps = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$apps): string {
				$apps[] = $app;
				$values = [
					SecurityTxtBuilder::CONTACT_KEY => "  mailto:security@gemeente.test \n",
					SecurityTxtBuilder::POLICY_KEY => '   ',
				];

				return ($values[$key] ?? $default);
			}
		);

		$body = (new SecurityTxtBuilder($appConfig))->build(now: new DateTimeImmutable('2026-09-16T00:00:00', new DateTimeZone('UTC')));

		$this->assertSame("Contact: mailto:security@gemeente.test\nExpires: 2026-12-15T00:00:00Z\n", $body);
		$this->assertSame(['openregister'], array_values(array_unique($apps)));
	}//end testValuesAreReadTrimmedFromThisAppAndBlankIsUnset()

	public function testAnUnreadableConfigurationServesNoFile(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(new RuntimeException('config table locked'));
		$builder = new SecurityTxtBuilder($appConfig);

		$this->assertNull($builder->build());
		$this->assertFalse($builder->isConfigured());
	}//end testAnUnreadableConfigurationServesNoFile()
}//end class
