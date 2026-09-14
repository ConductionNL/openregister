<?php

/**
 * Unit tests for RetentionClockService — two clocks, both visible, neither
 * silent.
 *
 * The failure mode this guards is silence: a product that merges the AVG date
 * and the Archiefwet date into one is wrong in one direction for every object
 * it holds, and nobody can tell which.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RetentionClockServiceTest extends TestCase {
	private function service(?string $retentionPeriod): RetentionClockService {
		$mapper = $this->createMock(VerwerkingsactiviteitMapper::class);
		if ($retentionPeriod === null) {
			$mapper->method('resolveReference')->willReturn(null);
		} else {
			$activity = new Verwerkingsactiviteit();
			$activity->setUuid('activity-1');
			$activity->setRetentionPeriod($retentionPeriod);
			$mapper->method('resolveReference')->willReturn($activity);
		}

		return new RetentionClockService($mapper, new NullLogger());
	}//end service()

	private function object(array $retention, ?string $activity = 'activity-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-100');
		$object->setUpdated(new DateTime('2026-01-01T00:00:00+00:00'));
		$object->setRetention($retention);
		if ($activity !== null) {
			$object->setProcessingActivityId($activity);
		}

		return $object;
	}//end object()

	public function testTheTwoDatesAreReadableAndSeparate(): void {
		// P1Y from the last write gives 2027; the selectielijst gives 2032.
		$clocks = $this->service('P1Y')->clocksFor(
			$this->object(
				[
					'archiefactiedatum' => '2032-01-01T00:00:00+00:00',
					'selectielijstBron' => 'VNG 2020',
					'bewaartermijn' => 'P10Y',
				]
			),
			new DateTimeImmutable('2026-06-01T00:00:00+00:00')
		);

		self::assertSame('2027-01-01T00:00:00+00:00', $clocks['avg']['date']);
		self::assertStringContainsString('activity-1', $clocks['avg']['rule']);
		self::assertStringContainsString('P1Y', $clocks['avg']['rule']);

		self::assertSame('2032-01-01T00:00:00+00:00', $clocks['archive']['date']);
		self::assertStringContainsString('VNG 2020', $clocks['archive']['rule']);
		self::assertStringContainsString('P10Y', $clocks['archive']['rule']);

		// They disagree, but the AVG date has not passed yet, so there is
		// nothing to decide today.
		self::assertNull($clocks['conflict']);
	}//end testTheTwoDatesAreReadableAndSeparate()

	public function testADisagreementIsReportedNotResolvedSilently(): void {
		$service = $this->service('P1Y');
		$object = $this->object(
			[
				'archiefactiedatum' => '2032-01-01T00:00:00+00:00',
				'selectielijstBron' => 'VNG 2020',
				'bewaartermijn' => 'P10Y',
			]
		);

		$now = new DateTimeImmutable('2028-01-01T00:00:00+00:00');
		$clocks = $service->clocksFor($object, $now);
		self::assertNotNull($clocks['conflict']);
		self::assertSame('RETENTION_CLOCKS_DISAGREE', $clocks['conflict']['code']);

		$refusal = $service->refusalFor($object, $now);
		self::assertNotNull($refusal);
		self::assertSame('retention-clocks-disagree', $refusal->getRule());

		// Both rules are named in the refusal, so the person deciding can see
		// what they are deciding between.
		$body = $refusal->toResponseBody();
		self::assertStringContainsString('activity-1', $body['clocks']['avg']['rule']);
		self::assertStringContainsString('VNG 2020', $body['clocks']['archive']['rule']);
	}//end testADisagreementIsReportedNotResolvedSilently()

	public function testAHoldOutranksBothClocks(): void {
		$service = $this->service('P1Y');
		$object = $this->object(
			[
				'archiefactiedatum' => '2020-01-01T00:00:00+00:00',
				'legalHold' => ['active' => true, 'reason' => 'bezwaarprocedure'],
			]
		);

		$now = new DateTimeImmutable('2028-01-01T00:00:00+00:00');
		$clocks = $service->clocksFor($object, $now);
		self::assertTrue($clocks['legalHold']);

		// The hold is the reason, and it is the ONLY reason: a held object is
		// never also reported as a clock conflict.
		self::assertNull($clocks['conflict']);

		$refusal = $service->refusalFor($object, $now);
		self::assertNotNull($refusal);
		self::assertSame('legal-hold', $refusal->getRule());
		self::assertStringContainsString('legal hold', $refusal->getMessage());
	}//end testAHoldOutranksBothClocks()

	public function testAnObjectWithNoActivityAndNoActionDateNamesWhyEachIsAbsent(): void {
		$clocks = $this->service(null)->clocksFor($this->object([], null));

		self::assertNull($clocks['avg']['date']);
		self::assertSame(RetentionClockService::RULE_NO_AVG, $clocks['avg']['rule']);
		self::assertNull($clocks['archive']['date']);
		self::assertSame(RetentionClockService::RULE_NO_ARCHIVE, $clocks['archive']['rule']);
		self::assertNull($clocks['conflict']);
	}//end testAnObjectWithNoActivityAndNoActionDateNamesWhyEachIsAbsent()

	public function testOneClockAloneIsNeverAConflict(): void {
		// Only the AVG clock is set, and it has passed. There is no second
		// rule to disagree with, so the pass proceeds.
		$service = $this->service('P1Y');
		$refusal = $service->refusalFor(
			$this->object([]),
			new DateTimeImmutable('2028-01-01T00:00:00+00:00')
		);

		self::assertNull($refusal);
	}//end testOneClockAloneIsNeverAConflict()
}//end class
