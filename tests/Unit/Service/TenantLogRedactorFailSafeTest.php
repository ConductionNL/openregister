<?php

/**
 * Fail-safe tests for TenantLogRedactor's counter and redact() wrapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\TenantLogRedactor;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A broken counter never resurrects a dropped line, and redact() never returns the raw payload.
 *
 * @covers \OCA\OpenRegister\Service\TenantLogRedactor
 */
class TenantLogRedactorFailSafeTest extends TestCase {

	/**
	 * Without an app config nothing is counted and the drop count reads zero.
	 *
	 * @return void
	 */
	public function testWithoutAppConfigNothingIsCounted(): void {
		$redactor = new TenantLogRedactor();

		$this->assertNull($redactor->line(context: ['handle' => fopen('php://memory', 'r')]));
		$this->assertSame(0, $redactor->droppedLines());
	}//end testWithoutAppConfigNothingIsCounted()

	/**
	 * An unreadable counter reads as zero, and a drop it cannot record is still a drop.
	 *
	 * @return void
	 */
	public function testBrokenCounterStillDropsTheLine(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willThrowException(new RuntimeException('config down'));
		$config->expects($this->never())->method('setValueInt');
		$redactor = new TenantLogRedactor(appConfig: $config);

		$this->assertNull($redactor->line(context: ['deep' => [[[[[[[['x']]]]]]]]]));
		$this->assertSame(0, $redactor->droppedLines());
	}//end testBrokenCounterStillDropsTheLine()

	/**
	 * A drop increments the stored counter by one.
	 *
	 * @return void
	 */
	public function testDropIncrementsTheCounter(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->with('openregister', TenantLogRedactor::DROPPED_COUNTER, 0)->willReturn(4);
		$config->expects($this->once())->method('setValueInt')->with('openregister', TenantLogRedactor::DROPPED_COUNTER, 5);

		$this->assertNull((new TenantLogRedactor(appConfig: $config))->line(context: ['bin' => "\xff\xfe"]));
	}//end testDropIncrementsTheCounter()

	/**
	 * redact() returns the cleaned payload, or a marker instead of the raw payload.
	 *
	 * @return void
	 */
	public function testRedactReturnsCleanPayloadOrFailureMarker(): void {
		$redactor = new TenantLogRedactor();

		$this->assertSame(
			['apiToken' => '[redacted]', 'url' => 'https[redacted]host/x', 'n' => 3],
			$redactor->redact(context: ['apiToken' => 'abc', 'url' => 'https://bob:hunter2@host/x', 'n' => 3])
		);
		$this->assertSame(['redactionFailed' => true], $redactor->redact(context: ['obj' => new \stdClass()]));
	}//end testRedactReturnsCleanPayloadOrFailureMarker()
}//end class
