<?php

/**
 * The working-pattern providers: one per app, registered lazily, asked in
 * turn, and passed over when they fail or answer malformed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Flow\Timer\RegisterWorkingPatternProvidersEvent;
use OCA\OpenRegister\Service\Flow\Timer\WorkingPatternProviderInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingPatternProviders;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingPatternProviders
 * @covers \OCA\OpenRegister\Service\Flow\Timer\RegisterWorkingPatternProvidersEvent
 */
class WorkingPatternProvidersTest extends TestCase {

	private IEventDispatcher&MockObject $dispatcher;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * @param array<string, mixed>|null $pattern
	 */
	private function provider(string $appId, ?array $pattern): WorkingPatternProviderInterface&MockObject {
		$provider = $this->createMock(WorkingPatternProviderInterface::class);
		$provider->method('appId')->willReturn($appId);
		$provider->method('patternFor')->willReturn($pattern);

		return $provider;
	}//end provider()

	/**
	 * A registry whose dispatcher registers these providers on the first question.
	 *
	 * @param array<int, WorkingPatternProviderInterface> $providers
	 */
	private function registry(array $providers): WorkingPatternProviders {
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function (RegisterWorkingPatternProvidersEvent $event) use ($providers): void {
				foreach ($providers as $provider) {
					$event->register(provider: $provider);
				}
			}
		);

		return new WorkingPatternProviders(dispatcher: $this->dispatcher, logger: $this->logger);
	}//end registry()

	public function testTheFirstProviderWithAPatternAnswersAndRegistrationHappensOnce(): void {
		$registry = $this->registry(
			[
				$this->provider(appId: 'quiet', pattern: null),
				$this->provider(appId: 'humaniq', pattern: ['workingWeekdays' => [3, 1, 1], 'hoursPerWorkingDay' => 6, 'absences' => ['2026-05-01', ['date' => '2026-05-04', 'name' => ' Verlof ']]]),
				$this->provider(appId: 'later', pattern: ['workingWeekdays' => [5]]),
			]
		);

		$expected = [
			'source' => 'humaniq',
			'workingWeekdays' => [1, 3],
			'hoursPerWorkingDay' => 6.0,
			'absences' => [['date' => '2026-05-01', 'name' => 'absence'], ['date' => '2026-05-04', 'name' => 'Verlof']],
		];
		$this->assertSame($expected, $registry->patternFor(userId: ' alice '));
		$this->assertSame($expected, $registry->patternFor(userId: 'alice'), 'asked again without registering again');
	}

	public function testNobodyAnsweringIsNull(): void {
		$this->assertNull($this->registry([$this->provider(appId: 'quiet', pattern: null)])->patternFor(userId: 'alice'));
	}

	public function testAnEmptyUserIsNotAskedAbout(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->assertNull((new WorkingPatternProviders(dispatcher: $this->dispatcher, logger: $this->logger))->patternFor(userId: '  '));
	}

	public function testAKeyLeftOutKeepsTheCalendarsOwn(): void {
		$pattern = $this->registry([$this->provider(appId: 'humaniq', pattern: [])])->patternFor(userId: 'alice');

		$this->assertSame(['source' => 'humaniq', 'workingWeekdays' => null, 'hoursPerWorkingDay' => null, 'absences' => []], $pattern);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function malformed(): array {
		return [
			'weekday out of range' => [['workingWeekdays' => [0]], 'not an ISO weekday'],
			'weekdays not a list' => [['workingWeekdays' => 'mon'], 'list of ISO weekdays'],
			'weekday as a string' => [['workingWeekdays' => ['1']], 'not an ISO weekday'],
			'no hours' => [['hoursPerWorkingDay' => 0], 'above 0 and at most 24'],
			'too many hours' => [['hoursPerWorkingDay' => 25], 'above 0 and at most 24'],
			'absences not a list' => [['absences' => '2026-05-01'], 'list of dates'],
			'absence not a date' => [['absences' => ['1 May']], 'not a Y-m-d date'],
		];
	}//end malformed()

	/**
	 * @param array<string, mixed> $answer
	 */
	#[DataProvider('malformed')]
	public function testAMalformedAnswerIsRefusedByNormalisation(array $answer, string $message): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage($message);

		WorkingPatternProviders::normalised(answer: $answer);
	}

	public function testAMalformedAnswerIsPassedOverForTheNextProvider(): void {
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('"broken"'));
		$registry = $this->registry([$this->provider(appId: 'broken', pattern: ['workingWeekdays' => [9]]), $this->provider(appId: 'humaniq', pattern: ['workingWeekdays' => [2]])]);

		$this->assertSame('humaniq', $registry->patternFor(userId: 'alice')['source']);
	}

	public function testAThrowingProviderIsLoggedAndPassedOver(): void {
		$throwing = $this->createMock(WorkingPatternProviderInterface::class);
		$throwing->method('appId')->willReturn('down');
		$throwing->method('patternFor')->willThrowException(new \RuntimeException('timeout'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('timeout'));

		$this->assertNull($this->registry([$throwing])->patternFor(userId: 'alice'));
	}

	public function testOneProviderPerApp(): void {
		$registry = new WorkingPatternProviders(dispatcher: $this->dispatcher, logger: $this->logger);
		$registry->register(provider: $this->provider(appId: 'humaniq', pattern: null));

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('"humaniq" already registered');
		$registry->register(provider: $this->provider(appId: ' humaniq ', pattern: null));
	}

	public function testAProviderMustNameItsApp(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('must name the app');

		(new WorkingPatternProviders(dispatcher: $this->dispatcher, logger: $this->logger))->register(provider: $this->provider(appId: ' ', pattern: null));
	}
}//end class
