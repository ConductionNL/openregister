<?php

/**
 * Golden test for HardeningReportService::controls.
 *
 * Records every control row (id, title, category, source, value, floor,
 * comparator, unit, note and state) for a set of platform and stored states, in
 * fixtures/hardening-report-service-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Hardening\HardeningAuditWriter;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsStore;
use OCA\OpenRegister\Service\Hardening\HardeningPosture;
use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportService;
use OCA\OpenRegister\Service\Hardening\PlatformSecurityReader;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningReportService
 * @uses   \OCA\OpenRegister\Service\Hardening\HardeningControl
 * @uses   \OCA\OpenRegister\Service\Hardening\HardeningPolicy
 * @uses   \OCA\OpenRegister\Service\Hardening\ThrottledSurfaces
 */
class HardeningReportServiceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/hardening-report-service-golden.json';

	/**
	 * Scenarios: [password policy, session policy, throttler, upload ceiling, stored app config, int config].
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$password = ['minimumLength' => 12, 'blocksCommonPasswords' => 1, 'checksBreachDatabase' => 1];
		$session = ['lifetimeSeconds' => 3600, 'rememberLoginSeconds' => 86400, 'secondFactorEnforced' => 1];
		return [
			'healthy' => [$password, $session, 1, 536870912, [], []],
			'unknown platform' => [
				['minimumLength' => null, 'blocksCommonPasswords' => null, 'checksBreachDatabase' => null],
				['lifetimeSeconds' => null, 'rememberLoginSeconds' => null, 'secondFactorEnforced' => 0],
				null,
				null,
				[],
				[],
			],
			'administered' => [
				['minimumLength' => 4, 'blocksCommonPasswords' => 0, 'checksBreachDatabase' => 1],
				['lifetimeSeconds' => 999999, 'rememberLoginSeconds' => 1, 'secondFactorEnforced' => 1],
				0,
				10,
				[
					'hardening_allowed_origins' => "https://a.example\nhttps://b.example",
					'hardening_floors' => '{"password.minimumLength":10,"upload.ceilingBytes":5,"origins.allowlistEntries":1}',
				],
				['hardening_auth_attempts_per_identity' => 3, 'hardening_auth_lockout_seconds' => 60],
			],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testControlsGolden(array $password, array $session, ?int $throttler, ?int $upload, array $stored, array $ints): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($ints[$key] ?? $default)
		);

		$platform = $this->createMock(PlatformSecurityReader::class);
		$platform->method('passwordPolicy')->willReturn($password);
		$platform->method('sessionPolicy')->willReturn($session);
		$platform->method('bruteForceState')->willReturn(
			['throttlerEnabled' => $throttler, 'delayMilliseconds' => 0, 'attempts' => 0, 'bypassListed' => false]
		);
		$capabilities = $this->createMock(ApiCapabilitiesService::class);
		$capabilities->method('uploadCeilingBytes')->willReturn($upload);

		$service = new HardeningReportService(
			new HardeningPosture(
				new HardeningPolicy($appConfig),
				new HardeningSettingsStore($appConfig, $this->createMock(AuditTrailMapperInterface::class), $this->createMock(LoggerInterface::class)),
				$this->createMock(HardeningAuditWriter::class),
				$platform
			),
			$capabilities
		);
		$actual = json_decode(json_encode($service->controls()), true);

		$name = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recorded for ' . $name);
		$this->assertSame($golden[$name], $actual);
	}
}
