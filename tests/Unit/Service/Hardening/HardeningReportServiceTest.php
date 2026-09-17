<?php

/**
 * HardeningReportServiceTest — what the administrator actually reads.
 *
 * The assertion that matters is the one about `unknown`. An instance running
 * without Nextcloud's password policy has no minimum length at all, and a
 * report that prints the shipped default there would tell a gemeente it
 * enforces ten characters on a system that accepts one.
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

use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportService;
use OCA\OpenRegister\Service\Hardening\PlatformSecurityReader;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfaces;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningReportService
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningControl
 */
class HardeningReportServiceTest extends TestCase {

	/**
	 * Build a report service over stubbed platform and capability readers.
	 *
	 * @param array<string, int|null> $password The password policy to report.
	 * @param array<string, int|null> $session The session policy to report.
	 * @param int|null $upload The upload ceiling to report.
	 * @param array<string, string> $stored The stored app configuration.
	 *
	 * @return HardeningReportService The service.
	 */
	private function service(
		array $password = ['minimumLength' => 12, 'blocksCommonPasswords' => 1, 'checksBreachDatabase' => 1],
		array $session = ['lifetimeSeconds' => 3600, 'rememberLoginSeconds' => 86400, 'secondFactorEnforced' => 1],
		?int $upload = 536870912,
		array $stored = [],
	): HardeningReportService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($stored[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => $default
		);

		$platform = $this->createMock(PlatformSecurityReader::class);
		$platform->method('passwordPolicy')->willReturn($password);
		$platform->method('sessionPolicy')->willReturn($session);
		$platform->method('passwordPolicyRuns')->willReturn($password['minimumLength'] !== null);
		$platform->method('bruteForceState')->willReturn(
			[
				'throttlerEnabled' => 1,
				'delayMilliseconds' => 0,
				'attempts' => 0,
				'bypassListed' => false,
			]
		);

		$capabilities = $this->createMock(ApiCapabilitiesService::class);
		$capabilities->method('uploadCeilingBytes')->willReturn($upload);

		return new HardeningReportService(new HardeningPolicy($appConfig), $platform, $capabilities);
	}

	/**
	 * Pull one row out of a report by its control id.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param string $id The control id.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(array $report, string $id): array {
		foreach ($report['controls'] as $control) {
			if ($control['id'] === $id) {
				return $control;
			}
		}

		$this->fail('The report does not carry ' . $id . '.');
	}

	public function testAHealthyInstanceMeetsEveryFloor(): void {
		$report = $this->service()->report();

		$this->assertSame([], $report['failing']);
		$this->assertTrue($report['meetsAllFloors']);
	}

	public function testAPasswordPolicyThatIsNotRunningReadsUnknownAndFails(): void {
		$report = $this->service(
			password: ['minimumLength' => null, 'blocksCommonPasswords' => null, 'checksBreachDatabase' => null]
		)->report();

		$row = $this->row(report: $report, id: 'password.minimumLength');

		$this->assertNull($row['value']);
		$this->assertSame('unknown', $row['state']);
		$this->assertFalse($row['meetsFloor']);
		$this->assertContains('password.minimumLength', $report['failing']);
		$this->assertFalse($report['meetsAllFloors']);
		$this->assertFalse($report['observed']['passwordPolicyRuns']);
	}

	public function testASessionLongerThanTheFloorIsNamedAsFailing(): void {
		$report = $this->service(
			session: ['lifetimeSeconds' => 604800, 'rememberLoginSeconds' => 86400, 'secondFactorEnforced' => 1]
		)->report();

		$row = $this->row(report: $report, id: 'session.lifetimeSeconds');

		$this->assertSame('atMost', $row['comparator']);
		$this->assertSame(86400, $row['floor']);
		$this->assertFalse($row['meetsFloor']);
		$this->assertContains('session.lifetimeSeconds', $report['failing']);
	}

	public function testAnUploadCeilingOverTheFloorFails(): void {
		$report = $this->service(upload: 4294967296)->report();

		$this->assertContains('upload.ceilingBytes', $report['failing']);
	}

	public function testAnUnreadableUploadCeilingAlsoFails(): void {
		$report = $this->service(upload: null)->report();

		$row = $this->row(report: $report, id: 'upload.ceilingBytes');

		$this->assertSame('unknown', $row['state']);
		$this->assertContains('upload.ceilingBytes', $report['failing']);
	}

	public function testTheReportNamesEverySurfaceThatRegistersAnAttempt(): void {
		$report = $this->service()->report();

		$this->assertSame(ThrottledSurfaces::ALL, $report['observed']['throttledSurfaces']);
		$this->assertSame(
			ThrottledSurfaces::count(),
			$this->row(report: $report, id: 'bruteForce.throttledSurfaces')['value']
		);
	}

	public function testAnEmptyAllowlistIsReportedAsReflectingAnyOrigin(): void {
		$report = $this->service()->report();

		$this->assertSame([], $report['observed']['allowedOrigins']);
		$this->assertTrue($report['observed']['reflectsAnyOrigin']);
	}

	public function testAConfiguredAllowlistIsReportedAsBinding(): void {
		$report = $this->service(stored: [HardeningPolicy::ORIGINS_KEY => 'https://example.nl'])->report();

		$this->assertSame(['https://example.nl'], $report['observed']['allowedOrigins']);
		$this->assertFalse($report['observed']['reflectsAnyOrigin']);
		$this->assertSame(1, $this->row(report: $report, id: 'origins.allowlistEntries')['value']);
	}

	public function testEveryControlCarriesASourceAFloorAndAComparator(): void {
		$report = $this->service()->report();

		$this->assertNotEmpty($report['controls']);
		foreach ($report['controls'] as $control) {
			$this->assertContains($control['source'], ['platform', 'administered', 'code']);
			$this->assertContains($control['comparator'], ['atLeast', 'atMost']);
			$this->assertArrayHasKey('floor', $control);
			$this->assertArrayHasKey('meetsFloor', $control);
		}
	}
}
