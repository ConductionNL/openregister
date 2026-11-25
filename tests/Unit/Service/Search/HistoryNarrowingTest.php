<?php

/**
 * HistoryNarrowing turns a history predicate into the candidate object ids, intersected across filters.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Db\StateChangePeriod;
use OCA\OpenRegister\Db\StateHistoryMapperInterface;
use OCA\OpenRegister\Service\Search\HistoryNarrowing;
use OCA\OpenRegister\Service\Search\HistoryPredicate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Was ever" and "changed between" filters each read the mapper once and narrow by intersection.
 */
#[CoversClass(HistoryNarrowing::class)]
#[UsesClass(HistoryPredicate::class)]
#[UsesClass(StateChangePeriod::class)]
class HistoryNarrowingTest extends TestCase {

	private StateHistoryMapperInterface&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	private HistoryNarrowing $narrowing;

	protected function setUp(): void {
		$this->mapper    = $this->createMock(StateHistoryMapperInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->narrowing = new HistoryNarrowing(mapper: $this->mapper, logger: $this->logger);
	}//end setUp()

	public function testEmptyPredicateReadsNothingAndReturnsTheIdsGiven(): void {
		$this->mapper->expects($this->never())->method('findObjectUuidsEverAt');
		$this->mapper->expects($this->never())->method('findObjectUuidsChangedBetween');
		$this->logger->expects($this->never())->method('debug');

		$predicate = HistoryPredicate::parse(query: []);

		$this->assertSame(['a', 'b'], $this->narrowing->narrow(predicate: $predicate, ids: ['a', 'b']));
		$this->assertSame([], $this->narrowing->narrow(predicate: $predicate));
	}//end testEmptyPredicateReadsNothingAndReturnsTheIdsGiven()

	public function testWasEverFiltersAreIntersectedAndDeduplicated(): void {
		$this->mapper->expects($this->exactly(2))
			->method('findObjectUuidsEverAt')
			->willReturnCallback(
				static fn (string $property, string $value): array => match ([$property, $value]) {
					['status', 'open'] => ['u1', 'u2', 'u2', 'u3'],
					['owner', 'alice'] => ['u2', 'u3', 'u4'],
				}
			);
		$this->logger->expects($this->once())
			->method('debug')
			->with($this->stringContains('[HistoryNarrowing]'), ['count' => 2]);

		$predicate = HistoryPredicate::parse(query: [HistoryPredicate::WAS_EVER => ['status' => 'open', 'owner' => 'alice']]);

		$this->assertSame(['u2', 'u3'], $this->narrowing->narrow(predicate: $predicate));
	}//end testWasEverFiltersAreIntersectedAndDeduplicated()

	public function testSingleWasEverFilterIsDeduplicated(): void {
		$this->mapper->method('findObjectUuidsEverAt')->willReturn(['u1', 'u1', 'u2']);

		$predicate = HistoryPredicate::parse(query: [HistoryPredicate::WAS_EVER => ['status' => 'open']]);

		$this->assertSame(['u1', 'u2'], $this->narrowing->narrow(predicate: $predicate));
	}//end testSingleWasEverFilterIsDeduplicated()

	public function testChangedBetweenPassesThePeriodAndNarrowsTheWasEverCandidates(): void {
		$this->mapper->method('findObjectUuidsEverAt')->with('status', 'open')->willReturn(['u1', 'u2', 'u3']);
		$this->mapper->expects($this->once())
			->method('findObjectUuidsChangedBetween')
			->with(
				'status',
				$this->callback(
					static fn (StateChangePeriod $period): bool => $period->after->format('Y-m-d') === '2026-01-01'
						&& $period->before->format('Y-m-d') === '2026-02-01'
				)
			)
			->willReturn(['u3', 'u1', 'u9']);

		$predicate = HistoryPredicate::parse(
			query: [
				HistoryPredicate::WAS_EVER => ['status' => 'open'],
				HistoryPredicate::CHANGED_BETWEEN => ['status' => '2026-01-01,2026-02-01'],
			]
		);

		$this->assertSame(['u1', 'u3'], $this->narrowing->narrow(predicate: $predicate));
	}//end testChangedBetweenPassesThePeriodAndNarrowsTheWasEverCandidates()

	public function testChangedBetweenAloneStartsTheCandidateSet(): void {
		$this->mapper->expects($this->never())->method('findObjectUuidsEverAt');
		$this->mapper->method('findObjectUuidsChangedBetween')->willReturn(['u5', 'u5', 'u6']);

		$predicate = HistoryPredicate::parse(
			query: [HistoryPredicate::CHANGED_BETWEEN => ['status' => ['after' => '2026-01-01', 'before' => '2026-03-01']]]
		);

		$this->assertSame(['u5', 'u6'], $this->narrowing->narrow(predicate: $predicate));
	}//end testChangedBetweenAloneStartsTheCandidateSet()

	public function testGivenIdsAreIntersectedWithTheCandidatesInTheirOwnOrder(): void {
		$this->mapper->method('findObjectUuidsEverAt')->willReturn(['u1', 'u2', 'u3']);

		$predicate = HistoryPredicate::parse(query: [HistoryPredicate::WAS_EVER => ['status' => 'open']]);

		$this->assertSame(['u3', 'u1'], $this->narrowing->narrow(predicate: $predicate, ids: ['u3', 'u7', 'u1']));
	}//end testGivenIdsAreIntersectedWithTheCandidatesInTheirOwnOrder()

	public function testEmptyIdListDoesNotNarrowTheCandidates(): void {
		$this->mapper->method('findObjectUuidsEverAt')->willReturn(['u1', 'u2']);

		$predicate = HistoryPredicate::parse(query: [HistoryPredicate::WAS_EVER => ['status' => 'open']]);

		$this->assertSame(['u1', 'u2'], $this->narrowing->narrow(predicate: $predicate, ids: []));
	}//end testEmptyIdListDoesNotNarrowTheCandidates()

	public function testNoMatchingHistoryYieldsNoCandidates(): void {
		$this->mapper->method('findObjectUuidsEverAt')->willReturn([]);
		$this->logger->expects($this->once())->method('debug')->with($this->anything(), ['count' => 0]);

		$predicate = HistoryPredicate::parse(query: [HistoryPredicate::WAS_EVER => ['status' => 'open']]);

		$this->assertSame([], $this->narrowing->narrow(predicate: $predicate, ids: ['u1']));
	}//end testNoMatchingHistoryYieldsNoCandidates()
}//end class
