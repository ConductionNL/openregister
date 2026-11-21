<?php

/**
 * Unit tests for HardeningSettingsService: origins that are not text or not an origin are refused, and a lost audit write is logged without undoing the change.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
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

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\HardeningFloorGuard;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningPosture;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsStore;
use OCA\OpenRegister\Service\Hardening\PlatformSecurityReader;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningSettingsService
 * @uses \OCA\OpenRegister\Db\AuditTrail\HardeningChange
 * @uses \OCA\OpenRegister\Service\Hardening\FloorRule
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningChange
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningFloorGuard
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningPolicy
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningPosture
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningSettingsStore
 */
class HardeningSettingsServiceEdgeTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private AuditTrailMapper&MockObject $auditTrailMapper;

	private LoggerInterface&MockObject $logger;

	/**
	 * The service over an empty configuration.
	 *
	 * @return HardeningSettingsService
	 */
	private function service(): HardeningSettingsService {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default): string => $default);
		$this->appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default): int => $default);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$policy = new HardeningPolicy($this->appConfig);

		return new HardeningSettingsService(
			new HardeningFloorGuard($policy),
			new HardeningPosture(
				$policy,
				new HardeningSettingsStore($this->appConfig, $this->auditTrailMapper, $this->logger),
				$this->createMock(HardeningAuditWriter::class),
				$this->createMock(PlatformSecurityReader::class)
			)
		);
	}//end service()

	public function testAnOriginThatIsNotTextIsRefusedAndNothingIsStored(): void {
		$service = $this->service();
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An origin must be written out as text.');
		$service->setAllowedOrigins(['https://gemeente.nl', 42]);
	}

	public function testAnOriginWithoutASchemeOrHostIsRefused(): void {
		$service = $this->service();
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An origin reads as https://example.nl, with no path.');
		$service->setAllowedOrigins(['gemeente.nl']);
	}

	public function testALostAuditWriteIsLoggedAndTheChangeStands(): void {
		$service = $this->service();
		$this->auditTrailMapper->method('recordHardeningChange')->willThrowException(new RuntimeException('audit table locked'));
		$this->appConfig->expects($this->once())->method('setValueInt')->with('openregister', 'hardening_auth_lockout_seconds', 3600);
		$this->logger->expects($this->once())->method('error')->with(
			'HardeningSettingsService: the audit entry for auth.rateLimit.lockoutSeconds could not be written: audit table locked'
		);

		$this->assertSame(3600, $service->setControl(control: 'auth.rateLimit.lockoutSeconds', value: 3600));
	}
}
