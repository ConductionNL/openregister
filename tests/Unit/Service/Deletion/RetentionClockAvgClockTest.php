<?php

/**
 * Characterisation tests for the AVG clock of RetentionClockService.
 *
 * Pins every way the AVG clock is absent (no activity linked, the activity
 * cannot be resolved, it is gone, it keeps no period, the object has no write
 * date, the period cannot be parsed) and the date it gives when present,
 * counting from the last write and falling back to creation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class RetentionClockAvgClockTest extends TestCase {

	/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> Log calls, as level, message, context. */
	private array $logs = [];

	/** @var array<int, string> The references the mapper was asked to resolve. */
	private array $resolved = [];

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->logs[] = [$level, $message, $context];
			});
		}

		return $logger;
	}

	/**
	 * @param Verwerkingsactiviteit|RuntimeException|null $activity What resolving the reference gives.
	 *
	 * @return RetentionClockService The service.
	 */
	private function service(Verwerkingsactiviteit|RuntimeException|null $activity): RetentionClockService {
		$mapper = $this->createMock(VerwerkingsactiviteitMapperInterface::class);
		$mapper->method('resolveReference')->willReturnCallback(function (string $reference) use ($activity): ?Verwerkingsactiviteit {
			$this->resolved[] = $reference;
			if ($activity instanceof RuntimeException) {
				throw $activity;
			}

			return $activity;
		});

		return new RetentionClockService($mapper, $this->logger());
	}

	private function activity(?string $period): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('activity-9');
		$activity->setRetentionPeriod($period);

		return $activity;
	}

	private function object(?string $reference, ?string $updated, ?string $created = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-9');
		if ($reference !== null) {
			$object->setProcessingActivityId($reference);
		}

		if ($updated !== null) {
			$object->setUpdated(new DateTime($updated));
		}

		if ($created !== null) {
			$object->setCreated(new DateTime($created));
		}

		return $object;
	}

	private function avg(RetentionClockService $service, ObjectEntity $object): array {
		return $service->clocksFor($object, new DateTimeImmutable('2026-06-01T00:00:00+00:00'))['avg'];
	}

	private function assertNoAvg(array $avg): void {
		$this->assertSame(['date' => null, 'rule' => RetentionClockService::RULE_NO_AVG], $avg);
	}

	public function testNoLinkedActivityGivesNoClockAndResolvesNothing(): void {
		$this->assertNoAvg($this->avg($this->service($this->activity('P1Y')), $this->object(null, '2026-01-01T00:00:00+00:00')));
		$this->assertNoAvg($this->avg($this->service($this->activity('P1Y')), $this->object('', '2026-01-01T00:00:00+00:00')));
		$this->assertSame([], $this->resolved);
		$this->assertSame([], $this->logs);
	}

	public function testAnActivityThatCannotBeResolvedIsLoggedAndGivesNoClock(): void {
		$avg = $this->avg($this->service(new RuntimeException('boom')), $this->object('ref-1', '2026-01-01T00:00:00+00:00'));

		$this->assertNoAvg($avg);
		$this->assertSame(['ref-1'], $this->resolved);
		$this->assertSame(
			[['debug', '[RetentionClocks] Could not resolve the processing activity', ['reference' => 'ref-1', 'error' => 'boom']]],
			$this->logs
		);
	}

	public function testAGoneActivityGivesNoClock(): void {
		$this->assertNoAvg($this->avg($this->service(null), $this->object('ref-1', '2026-01-01T00:00:00+00:00')));
		$this->assertSame([], $this->logs);
	}

	public function testAnActivityWithoutAPeriodGivesNoClock(): void {
		$this->assertNoAvg($this->avg($this->service($this->activity(null)), $this->object('ref-1', '2026-01-01T00:00:00+00:00')));
		$this->assertNoAvg($this->avg($this->service($this->activity('')), $this->object('ref-1', '2026-01-01T00:00:00+00:00')));
		$this->assertSame([], $this->logs);
	}

	public function testAnObjectWithoutAWriteDateGivesNoClock(): void {
		$this->assertNoAvg($this->avg($this->service($this->activity('P1Y')), $this->object('ref-1', null)));
		$this->assertSame([], $this->logs);
	}

	public function testAnUnparseablePeriodIsLoggedAndGivesNoClock(): void {
		$avg = $this->avg($this->service($this->activity('one year')), $this->object('ref-1', '2026-01-01T00:00:00+00:00'));

		$this->assertNoAvg($avg);
		$this->assertSame(
			[['warning', '[RetentionClocks] Unparseable retentionPeriod on a processing activity', ['reference' => 'ref-1', 'retentionPeriod' => 'one year']]],
			$this->logs
		);
	}

	public function testThePeriodCountsFromTheLastWrite(): void {
		$avg = $this->avg(
			$this->service($this->activity('P1Y')),
			$this->object('ref-1', '2026-01-01T00:00:00+00:00', '2020-01-01T00:00:00+00:00')
		);

		$this->assertSame(
			[
				'date' => '2027-01-01T00:00:00+00:00',
				'rule' => 'verwerkingsactiviteit activity-9 keeps this data for P1Y after the last processing',
			],
			$avg
		);
		$this->assertSame([], $this->logs);
	}

	public function testWithoutAWriteThePeriodCountsFromCreation(): void {
		$avg = $this->avg($this->service($this->activity('P6M')), $this->object('ref-1', null, '2026-01-01T00:00:00+00:00'));

		$this->assertSame('2026-07-01T00:00:00+00:00', $avg['date']);
		$this->assertSame('verwerkingsactiviteit activity-9 keeps this data for P6M after the last processing', $avg['rule']);
	}
}//end class
