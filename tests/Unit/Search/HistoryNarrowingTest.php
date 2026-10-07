<?php

/**
 * Unit tests for the history narrowing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\StateHistoryMapper;
use OCA\OpenRegister\Service\Search\HistoryNarrowing;
use OCA\OpenRegister\Service\Search\HistoryPredicate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HistoryNarrowingTest extends TestCase {

	private StateHistoryMapper&MockObject $mapper;

	private HistoryNarrowing $narrowing;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(StateHistoryMapper::class);
		$this->narrowing = new HistoryNarrowing($this->mapper, $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * One filter answers its own candidate set.
	 *
	 * @return void
	 */
	public function testOneFilterAnswersItsCandidates(): void {
		$this->mapper->method('findObjectUuidsEverAt')->with('status', 'bezwaar')->willReturn(['a', 'b']);

		$narrowed = $this->narrowing->narrow(
			predicate: HistoryPredicate::parse(['_was_ever' => ['status' => 'bezwaar']])
		);

		$this->assertSame(['a', 'b'], $narrowed);
	}//end testOneFilterAnswersItsCandidates()

	/**
	 * Two filters in one query bar mean BOTH, so the sets intersect. A union
	 * would widen the answer and every extra filter would return more rows.
	 *
	 * @return void
	 */
	public function testTwoFiltersIntersectRatherThanUnion(): void {
		$this->mapper->method('findObjectUuidsEverAt')->willReturn(['a', 'b', 'c']);
		$this->mapper->method('findObjectUuidsChangedBetween')->willReturn(['b', 'c', 'd']);

		$narrowed = $this->narrowing->narrow(
			predicate: HistoryPredicate::parse(
				[
					'_was_ever' => ['status' => 'bezwaar'],
					'_changed_between' => ['status' => '2026-01-01,2026-06-30'],
				]
			)
		);

		sort($narrowed);
		$this->assertSame(['b', 'c'], $narrowed);
	}//end testTwoFiltersIntersectRatherThanUnion()

	/**
	 * The id set the query already carries is intersected too: a history
	 * filter can only ever take objects away from what the caller could
	 * already see, never add one.
	 *
	 * @return void
	 */
	public function testTheExistingIdSetIsNarrowedAndNeverWidened(): void {
		$this->mapper->method('findObjectUuidsEverAt')->willReturn(['a', 'b', 'z']);

		$narrowed = $this->narrowing->narrow(
			predicate: HistoryPredicate::parse(['_was_ever' => ['status' => 'bezwaar']]),
			ids: ['b', 'c']
		);

		$this->assertSame(['b'], $narrowed);
	}//end testTheExistingIdSetIsNarrowedAndNeverWidened()

	/**
	 * A filter matching nothing answers an empty set, which the caller turns
	 * into an empty page. It must not answer "no filter".
	 *
	 * @return void
	 */
	public function testAFilterMatchingNothingAnswersAnEmptySet(): void {
		$this->mapper->method('findObjectUuidsEverAt')->willReturn([]);

		$narrowed = $this->narrowing->narrow(
			predicate: HistoryPredicate::parse(['_was_ever' => ['status' => 'bezwaar']]),
			ids: ['a', 'b']
		);

		$this->assertSame([], $narrowed);
	}//end testAFilterMatchingNothingAnswersAnEmptySet()
}//end class
