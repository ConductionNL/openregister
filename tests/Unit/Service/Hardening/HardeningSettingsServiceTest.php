<?php

/**
 * HardeningSettingsServiceTest — the write path, and the row it leaves behind.
 *
 * Two assertions carry this file. A refused weakening must not reach the
 * configuration, and it must still reach the audit trail: "somebody tried" is
 * the row a security officer wants most, and a success-only trail cannot hold
 * it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Hardening\HardeningFloorException;
use OCA\OpenRegister\Service\Hardening\HardeningFloorGuard;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningSettingsService
 */
class HardeningSettingsServiceTest extends TestCase {

	/**
	 * The stubbed configuration the service writes to.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The stubbed audit trail mapper.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $auditTrailMapper;

	/**
	 * The rows the service asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Build a settings service over stubbed storage.
	 *
	 * @param array<string, string> $stored The stored strings.
	 *
	 * @return HardeningSettingsService The service.
	 */
	private function service(array $stored = []): HardeningSettingsService {
		$this->rows = [];

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($stored[$key] ?? $default)
		);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => $default
		);

		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditTrailMapper->method('createHardeningChangeEntry')->willReturnCallback(
			function (string $control, int|string|array $before, int|string|array $after, bool $accepted, string $refusal = ''): AuditTrail {
				$this->rows[] = [
					'control' => $control,
					'before' => $before,
					'after' => $after,
					'accepted' => $accepted,
					'refusal' => $refusal,
				];

				return new AuditTrail();
			}
		);

		$policy = new HardeningPolicy($this->appConfig);

		return new HardeningSettingsService(
			$policy,
			new HardeningFloorGuard($policy),
			$this->appConfig,
			$this->auditTrailMapper,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testATighteningIsStoredAndAudited(): void {
		$service = $this->service();
		$this->appConfig->expects($this->once())
			->method('setValueInt')
			->with('openregister', 'hardening_auth_lockout_seconds', 3600);

		$this->assertSame(3600, $service->setControl(control: 'auth.rateLimit.lockoutSeconds', value: 3600));

		$this->assertCount(1, $this->rows);
		$this->assertTrue($this->rows[0]['accepted']);
		$this->assertSame(900, $this->rows[0]['before']);
		$this->assertSame(3600, $this->rows[0]['after']);
	}

	public function testAWeakeningIsRefusedNeverStoredAndStillAudited(): void {
		$service = $this->service();
		$this->appConfig->expects($this->never())->method('setValueInt');

		try {
			$service->setControl(control: 'auth.rateLimit.lockoutSeconds', value: 60);
			$this->fail('The service stored a lockout below the floor.');
		} catch (HardeningFloorException $refusal) {
			$this->assertSame('auth.rateLimit.lockoutSeconds', $refusal->control);
		}

		$this->assertCount(1, $this->rows);
		$this->assertFalse($this->rows[0]['accepted']);
		$this->assertStringContainsString('900', $this->rows[0]['refusal']);
	}

	public function testAControlThisInstanceDoesNotAdministerIsRefused(): void {
		$service = $this->service();

		$this->expectException(InvalidArgumentException::class);
		$service->setControl(control: 'password.minimumLength', value: 4);
	}

	public function testAnAllowlistIsNormalisedBeforeItIsStored(): void {
		$service = $this->service();
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', HardeningPolicy::ORIGINS_KEY, 'https://example.nl,https://other.nl:8443');

		$stored = $service->setAllowedOrigins(
			origins: [' https://Example.nl ', 'https://example.nl', 'https://other.nl:8443', '']
		);

		$this->assertSame(['https://example.nl', 'https://other.nl:8443'], $stored);
	}

	public function testAnOriginCarryingAPathIsRefused(): void {
		$service = $this->service();

		$this->expectException(InvalidArgumentException::class);
		$service->setAllowedOrigins(origins: ['https://example.nl/portal']);
	}

	public function testAnOriginOnAnotherSchemeIsRefused(): void {
		$service = $this->service();

		$this->expectException(InvalidArgumentException::class);
		$service->setAllowedOrigins(origins: ['ftp://example.nl']);
	}

	public function testEmptyingAnAllowlistIsRefusedWhenTheFloorRequiresOne(): void {
		$service = $this->service(
			stored: [
				HardeningPolicy::ORIGINS_KEY => 'https://example.nl',
				HardeningPolicy::FLOORS_KEY => '{"origins.allowlistEntries":1}',
			]
		);

		$this->expectException(HardeningFloorException::class);
		$service->setAllowedOrigins(origins: []);
	}

	public function testAFloorIsStoredBesideTheOnesAlreadyDeclared(): void {
		$service = $this->service(stored: [HardeningPolicy::FLOORS_KEY => '{"session.lifetimeSeconds":3600}']);
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with(
				'openregister',
				HardeningPolicy::FLOORS_KEY,
				'{"session.lifetimeSeconds":3600,"auth.rateLimit.lockoutSeconds":1800}'
			);

		$this->assertSame(1800, $service->setFloor(control: 'auth.rateLimit.lockoutSeconds', floor: 1800));
		$this->assertTrue($this->rows[0]['accepted']);
	}

	public function testAFloorWeakerThanTheBaselineIsRefusedAndAudited(): void {
		$service = $this->service();
		$this->appConfig->expects($this->never())->method('setValueString');

		try {
			$service->setFloor(control: 'auth.rateLimit.attemptsPerIdentity', floor: 200);
			$this->fail('The service stored a floor weaker than the baseline.');
		} catch (HardeningFloorException $refusal) {
			$this->assertSame(20, $refusal->floor);
		}

		$this->assertCount(1, $this->rows);
		$this->assertFalse($this->rows[0]['accepted']);
	}

	public function testAFloorOnAnUnknownControlIsRefused(): void {
		$service = $this->service();

		$this->expectException(InvalidArgumentException::class);
		$service->setFloor(control: 'invented.control', floor: 1);
	}
}
