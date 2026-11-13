<?php

/**
 * The API call record never breaks a request: a switch that cannot be read
 * reads as off, the record is on by default, and a session that throws
 * names an anonymous caller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiCaller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiCaller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\ApiCallRecordMapperInterface;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ApiCallRecorder::isEnabled() and principal() fail-safes.
 *
 * @covers \OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder
 */
class ApiCallRecorderFailSafeTest extends TestCase {

	private function recorder(IAppConfig $appConfig, ?IUserSession $session = null): ApiCallRecorder {
		return new ApiCallRecorder(
			$this->createMock(ApiCallRecordMapperInterface::class),
			($session ?? $this->createMock(IUserSession::class)),
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);
	}//end recorder()

	public function testTheRecordIsOnUnlessSwitchedOff(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueBool')
			->with('openregister', ApiCallRecorder::ENABLED_KEY, true)
			->willReturn(true);

		$this->assertTrue($this->recorder($appConfig)->isEnabled());
	}//end testTheRecordIsOnUnlessSwitchedOff()

	public function testASwitchThatCannotBeReadReadsAsOff(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willThrowException(new RuntimeException('type conflict'));

		$this->assertFalse($this->recorder($appConfig)->isEnabled());
	}//end testASwitchThatCannotBeReadReadsAsOff()

	public function testASessionThatThrowsNamesAnAnonymousCaller(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willThrowException(new RuntimeException('session closed'));

		$this->assertSame('', $this->recorder($this->createMock(IAppConfig::class), $session)->principal());
	}//end testASessionThatThrowsNamesAnAnonymousCaller()
}//end class
