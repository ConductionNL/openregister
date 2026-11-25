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

use OCA\OpenRegister\Service\Hardening\PlatformSecurityReader;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\HardeningPosture;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\HardeningChange as HardeningAuditChange;
use OCA\OpenRegister\Service\Hardening\HardeningFloorException;
use OCA\OpenRegister\Service\Hardening\HardeningFloorGuard;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsStore;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningSettingsService
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningControl
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningFloorException
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningFloorGuard
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningPolicy
 * @uses \OCA\OpenRegister\Db\AuditTrail\HardeningChange
 * @uses \OCA\OpenRegister\Service\Hardening\FloorBreach
 * @uses \OCA\OpenRegister\Service\Hardening\FloorRule
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningChange
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningPosture
 * @uses \OCA\OpenRegister\Service\Hardening\HardeningSettingsStore
 * @uses \OCA\OpenRegister\Service\Hardening\AddressAllowlist
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
		$this->auditTrailMapper->method('recordHardeningChange')->willReturnCallback(
			function (HardeningAuditChange $change, bool $accepted): AuditTrail {
				[$control, $before, $after, $refusal] = [$change->control, $change->before, $change->after, $change->refusal];
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
			new HardeningFloorGuard($policy),
			new HardeningPosture(
				$policy,
				new HardeningSettingsStore($this->appConfig, $this->auditTrailMapper, $this->createMock(LoggerInterface::class)),
				$this->createMock(HardeningAuditWriter::class),
				$this->createMock(PlatformSecurityReader::class)
			)
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

	/**
	 * An address allowlist is normalised, stored and audited.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function testAnAddressAllowlistIsNormalisedStoredAndAudited(): void {
		$service = $this->service(['hardening_admin_addresses' => '198.51.100.1']);
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'hardening_admin_addresses', '192.0.2.0/24,2001:db8::/32');

		$stored = $service->setAddressAllowlist(
			surface: 'admin',
			entries: [' 192.0.2.0/24 ', '2001:DB8::/32', '', '192.0.2.0/24'],
			requesterAddress: '192.0.2.44'
		);

		$this->assertSame(['192.0.2.0/24', '2001:db8::/32'], $stored);
		$this->assertSame(
			[['control' => 'addresses.admin', 'before' => ['198.51.100.1'], 'after' => ['192.0.2.0/24', '2001:db8::/32'], 'accepted' => true, 'refusal' => '']],
			$this->rows
		);
	}

	/**
	 * 🔴 A list that would refuse the address the change comes from is refused, never stored, and audited.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function testAListThatLocksItsAuthorOutIsRefusedAndAudited(): void {
		$service = $this->service();
		$this->appConfig->expects($this->never())->method('setValueString');

		try {
			$service->setAddressAllowlist(surface: 'api', entries: ['192.0.2.0/24'], requesterAddress: '203.0.113.9');
			$this->fail('the lock-out was stored');
		} catch (InvalidArgumentException $refused) {
			$this->assertStringContainsString('would refuse the address this change comes from', $refused->getMessage());
		}

		$this->assertCount(1, $this->rows);
		$this->assertSame('addresses.api', $this->rows[0]['control']);
		$this->assertFalse($this->rows[0]['accepted']);
		$this->assertNotSame('', $this->rows[0]['refusal']);
	}

	/**
	 * An empty list unbinds the surface, wherever the change comes from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function testAnEmptyListUnbindsTheSurface(): void {
		$service = $this->service(['hardening_api_addresses' => '192.0.2.0/24']);
		$this->appConfig->expects($this->once())->method('setValueString')->with('openregister', 'hardening_api_addresses', '');

		$this->assertSame([], $service->setAddressAllowlist(surface: 'api', entries: [' '], requesterAddress: '203.0.113.9'));
	}

	/**
	 * A list holding an entry that is no address, or a non-text entry, or for an unknown surface, is refused whole.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function testAnInvalidAddressListIsRefusedWhole(): void {
		foreach ([['admin', ['192.0.2.1', 'example.nl']], ['admin', [42]], ['files', ['192.0.2.1']]] as [$surface, $entries]) {
			$service = $this->service();
			$this->appConfig->expects($this->never())->method('setValueString');
			try {
				$service->setAddressAllowlist(surface: $surface, entries: $entries, requesterAddress: '192.0.2.1');
				$this->fail('stored '.json_encode($entries).' for '.$surface);
			} catch (InvalidArgumentException) {
				$this->assertSame([], $this->rows, 'nothing was changed, so nothing is audited');
			}
		}
	}

	/**
	 * The policy reads an unbound surface as open and a bound one by its entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function testThePolicyAllowsAnAddressPerSurface(): void {
		$this->service();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => (['hardening_admin_addresses' => '192.0.2.0/24, bogus'][$key] ?? $default)
		);
		$policy = new HardeningPolicy($appConfig);

		$this->assertSame(['192.0.2.0/24'], $policy->addressAllowlist(surface: 'admin'));
		$this->assertTrue($policy->allowsAddress(surface: 'admin', address: '192.0.2.9'));
		$this->assertFalse($policy->allowsAddress(surface: 'admin', address: '198.51.100.9'));
		$this->assertTrue($policy->allowsAddress(surface: 'api', address: '198.51.100.9'), 'the API list is empty: unbound');
		$this->assertTrue($policy->allowsAddress(surface: 'files', address: '198.51.100.9'), 'an unknown surface is not bound');

		$broken = $this->createMock(IAppConfig::class);
		$broken->method('getValueString')->willThrowException(new \RuntimeException('down'));
		$this->assertTrue((new HardeningPolicy($broken))->allowsAddress(surface: 'admin', address: '198.51.100.9'), 'an unreadable list is unbound');
	}
}
