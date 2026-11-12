<?php

/**
 * Unit tests for MaintenanceModeService — closing the instance and opening it again.
 *
 * The mode lives in app configuration. These tests hold that configuration in
 * an in-memory map behind the IConfig mock, so every assertion reads what the
 * service actually wrote, and every branch (no message, a blank message, no
 * actor, no since) is decided by the value the test put there.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Operations\OperatorAct;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\MaintenanceModeService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeServiceTest extends TestCase {

	/**
	 * The app configuration as the service sees it, keyed by setting.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * The recorder mock; acts are asserted on it.
	 *
	 * @var JobRunRecorder&MockObject
	 */
	private JobRunRecorder&MockObject $recorder;

	private function service(array $initial = []): MaintenanceModeService {
		$this->store = $initial;
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame('openregister', $app);
				return $this->store[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, $value): void {
				$this->assertSame('openregister', $app);
				$this->store[$key] = (string) $value;
			}
		);
		$config->method('deleteAppValue')->willReturnCallback(
			function (string $app, string $key): void {
				$this->assertSame('openregister', $app);
				unset($this->store[$key]);
			}
		);
		$this->recorder = $this->createMock(JobRunRecorder::class);
		return new MaintenanceModeService($config, $this->recorder);
	}

	public function testHoldsOnlyWhenTheSettingIsYes(): void {
		$this->assertFalse($this->service()->holds());
		$this->assertFalse($this->service(['maintenance_mode' => 'no'])->holds());
		$this->assertFalse($this->service(['maintenance_mode' => 'true'])->holds());
		$this->assertTrue($this->service(['maintenance_mode' => 'yes'])->holds());
	}

	public function testMessageFallsBackToTheShippedTextWhenUnsetOrBlank(): void {
		$this->assertSame(MaintenanceModeService::DEFAULT_MESSAGE, $this->service()->message());
		$this->assertSame(MaintenanceModeService::DEFAULT_MESSAGE, $this->service(['maintenance_mode_message' => "  \t "])->message());
	}

	public function testMessageReturnsTheAdministeredText(): void {
		$this->assertSame('Back at noon', $this->service(['maintenance_mode_message' => 'Back at noon'])->message());
	}

	public function testStateReportsNullSinceAndActorWhenNeverClosed(): void {
		$this->assertSame(
			['holds' => false, 'message' => MaintenanceModeService::DEFAULT_MESSAGE, 'since' => null, 'actor' => null],
			$this->service()->state()
		);
	}

	public function testStateReportsWhoClosedItAndWhen(): void {
		$state = $this->service(
			[
				'maintenance_mode' => 'yes',
				'maintenance_mode_message' => 'Migrating',
				'maintenance_mode_since' => '2026-01-02T03:04:05+00:00',
				'maintenance_mode_actor' => 'alice',
			]
		)->state();

		$this->assertSame(
			['holds' => true, 'message' => 'Migrating', 'since' => '2026-01-02T03:04:05+00:00', 'actor' => 'alice'],
			$state
		);
	}

	public function testEnterClosesTheInstanceStoresTheMessageAndRecordsTheAct(): void {
		$service = $this->service();
		$this->recorder->expects($this->once())->method('recordAct')->with(
			new OperatorAct(MaintenanceModeService::ACT_ENTER, 'alice', ['message' => 'Upgrading'], 'Entered maintenance mode.')
		);

		$state = $service->enter('alice', 'Upgrading');

		$this->assertTrue($state['holds']);
		$this->assertSame('Upgrading', $state['message']);
		$this->assertSame('alice', $state['actor']);
		$this->assertSame('yes', $this->store['maintenance_mode']);
		$this->assertSame('Upgrading', $this->store['maintenance_mode_message']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $state['since']);
		$this->assertSame($state['since'], $this->store['maintenance_mode_since']);
	}

	public function testEnterWithABlankMessageKeepsThePreviousOne(): void {
		$service = $this->service(['maintenance_mode_message' => 'Earlier text']);
		$this->recorder->expects($this->once())->method('recordAct')->with(
			new OperatorAct(MaintenanceModeService::ACT_ENTER, 'bob', ['message' => 'Earlier text'], 'Entered maintenance mode.')
		);

		$state = $service->enter('bob', '   ');

		$this->assertSame('Earlier text', $this->store['maintenance_mode_message']);
		$this->assertSame('Earlier text', $state['message']);
	}

	public function testEnterWithoutAMessageWritesNoMessageSetting(): void {
		$service = $this->service();

		$state = $service->enter('bob');

		$this->assertArrayNotHasKey('maintenance_mode_message', $this->store);
		$this->assertSame(MaintenanceModeService::DEFAULT_MESSAGE, $state['message']);
	}

	public function testLeaveOpensTheInstanceForgetsWhoAndWhenAndRecordsTheAct(): void {
		$service = $this->service(
			[
				'maintenance_mode' => 'yes',
				'maintenance_mode_message' => 'Migrating',
				'maintenance_mode_since' => '2026-01-02T03:04:05+00:00',
				'maintenance_mode_actor' => 'alice',
			]
		);
		$this->recorder->expects($this->once())->method('recordAct')->with(
			new OperatorAct(MaintenanceModeService::ACT_LEAVE, 'carol', [], 'Left maintenance mode.')
		);

		$state = $service->leave('carol');

		$this->assertSame(
			['holds' => false, 'message' => 'Migrating', 'since' => null, 'actor' => null],
			$state
		);
		$this->assertSame('no', $this->store['maintenance_mode']);
		$this->assertArrayNotHasKey('maintenance_mode_since', $this->store);
		$this->assertArrayNotHasKey('maintenance_mode_actor', $this->store);
	}
}
