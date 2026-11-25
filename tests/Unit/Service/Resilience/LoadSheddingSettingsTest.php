<?php

/**
 * Unit test for LoadSheddingSettings: the load shedding thresholds form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Resilience
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Resilience;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Resilience\LoadSheddingSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(LoadSheddingSettings::class)]
class LoadSheddingSettingsTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<string, int|bool> What the config holds. */
	private array $stored = [];

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default): int => (int) ($this->stored[$key] ?? $default)
		);
		$this->appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default): bool => (bool) ($this->stored[$key] ?? $default)
		);
	}

	private function settings(): LoadSheddingSettings {
		return new LoadSheddingSettings($this->appConfig, $this->logger);
	}

	public function testReadingAnEmptyConfigAnswersTheDesignDefaults(): void {
		$form = $this->settings()->read();

		$this->assertSame(
			[
				'pressureEnabled' => true,
				'breakerMinCalls' => 10,
				'breakerMinFailures' => 5,
				'breakerFailurePercent' => 50,
				'breakerCoolDown' => 30,
				'breakerMaxCoolDown' => 300,
				'pressureMinRequests' => 50,
				'pressureSlowMs' => 2000,
				'pressureSlowPercent' => 20,
				'pressureErrorPercent' => 10,
			],
			$form['values']
		);
		$this->assertSame($form['values'], $form['defaults']);
		$this->assertSame(['min' => 1, 'max' => 100], $form['bounds']['breakerFailurePercent']);
		$this->assertSame(['min' => 1, 'max' => 2147483647], $form['bounds']['breakerCoolDown']);
	}

	public function testReadingReportsStoredValuesAndTheSwitch(): void {
		$this->stored = [
			'load_shedding_breaker_cool_down' => 45,
			'load_shedding_pressure_slow_ms' => 3000,
			'load_shedding_pressure_enabled' => false,
		];

		$values = $this->settings()->read()['values'];

		$this->assertSame(45, $values['breakerCoolDown']);
		$this->assertSame(3000, $values['pressureSlowMs']);
		$this->assertFalse($values['pressureEnabled']);
	}

	public function testAnOutOfRangeStoredValueIsReportedAsItsDefault(): void {
		$this->stored = ['load_shedding_breaker_failure_percent' => 150, 'load_shedding_pressure_min_requests' => 0];

		$values = $this->settings()->read()['values'];

		$this->assertSame(50, $values['breakerFailurePercent']);
		$this->assertSame(50, $values['pressureMinRequests']);
	}

	public function testAnUnreadableConfigReportsDefaultsAndWarns(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willThrowException(new RuntimeException('type conflict'));
		$appConfig->method('getValueBool')->willThrowException(new RuntimeException('type conflict'));
		$this->logger->expects($this->exactly(10))->method('warning');

		$values = (new LoadSheddingSettings($appConfig, $this->logger))->read()['values'];

		$this->assertTrue($values['pressureEnabled']);
		$this->assertSame(10, $values['breakerMinCalls']);
	}

	public function testWritingStoresOnlyTheFieldsSentUnderTheirKeys(): void {
		$written = [];
		$this->appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value) use (&$written): bool {
				$this->assertSame('openregister', $app);
				$written[$key]      = $value;
				$this->stored[$key] = $value;
				return true;
			}
		);
		$this->appConfig->expects($this->once())->method('setValueBool')
			->with('openregister', 'load_shedding_pressure_enabled', false)
			->willReturnCallback(function () {
				$this->stored['load_shedding_pressure_enabled'] = false;
				return true;
			});

		$form = $this->settings()->write(['breakerCoolDown' => '60', 'pressureSlowPercent' => 35, 'pressureEnabled' => false, 'unknown' => 4]);

		$this->assertSame(['load_shedding_breaker_cool_down' => 60, 'load_shedding_pressure_slow_percent' => 35], $written);
		$this->assertSame(60, $form['values']['breakerCoolDown']);
		$this->assertSame(35, $form['values']['pressureSlowPercent']);
		$this->assertFalse($form['values']['pressureEnabled']);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedForms(): array {
		return [
			'percent above 100' => [['breakerFailurePercent' => 101], 'breakerFailurePercent must be a whole number from 1 to 100.'],
			'zero cool-down' => [['breakerCoolDown' => 0], 'breakerCoolDown must be a whole number from 1 to 2147483647.'],
			'a fraction' => [['pressureSlowMs' => '2.5'], 'pressureSlowMs must be a whole number from 1 to 2147483647.'],
			'a boolean number' => [['breakerMinCalls' => true], 'breakerMinCalls must be a whole number from 1 to 2147483647.'],
			'a string switch' => [['pressureEnabled' => 'no'], 'pressureEnabled must be true or false.'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedForms')]
	public function testARefusedFieldStoresNothing(array $input, string $message): void {
		$this->appConfig->expects($this->never())->method('setValueInt');
		$this->appConfig->expects($this->never())->method('setValueBool');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		// A valid field first: it must not be stored when a later one is refused.
		$this->settings()->write(['breakerMinFailures' => 6] + $input);
	}
}
