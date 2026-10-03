<?php

/**
 * The merged feed's order, its bound, its cursor and its read filter.
 *
 * Four of these assertions exist because the failure they catch is silent.
 *
 * 🔴 A MERGE THAT DROPS ROWS LOOKS LIKE AN OBJECT WITH LESS HISTORY. Paging
 * five sources on five offsets loses rows the moment the sources are unequal,
 * and nothing anywhere says so: the reader sees a shorter list and believes
 * it. The cursor is therefore a TIME, and the test pages twice and asserts
 * that the two pages together hold every row exactly once.
 *
 * 🔴 A TIE THAT SORTS DIFFERENTLY ON EVERY REQUEST BREAKS PAGING THE SAME WAY.
 * Two rows written in the same second are separated by nothing unless the sort
 * says what separates them, so the tie-break is asserted rather than assumed.
 *
 * 🔴 EXCLUDING READS MUST NOT EXCLUDE A NOTE. Only an audit row can be a read;
 * a note whose action happens to read `read` is still a note, and filtering it
 * out empties a chip the reader deliberately turned on.
 *
 * 🔴 A ROW WITH NO TIME MUST NOT HEAD THE FEED. An unparseable moment sorting
 * as "now" puts a row nobody can date at the top of a list that is read as a
 * sequence, which is worse than leaving it out.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\ActivityFeedMerge;
use PHPUnit\Framework\TestCase;

/**
 * The merge engine.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
class ActivityFeedMergeTest extends TestCase {

	private ActivityFeedMerge $merge;

	protected function setUp(): void {
		parent::setUp();
		$this->merge = new ActivityFeedMerge();
	}//end setUp()

	/**
	 * An object that was edited, then got a file, then got a note.
	 *
	 * @return array<string,array<int,array<string,mixed>>> The sources.
	 */
	private function threeWrites(): array {
		return [
			'audit' => [['id' => 'a1', 'action' => 'update', 'timestamp' => 100, 'user' => 'alice', 'summary' => 'edited']],
			'file' => [['id' => 'f1', 'timestamp' => 200, 'actor' => 'bob', 'summary' => 'gevel.jpg']],
			'note' => [['id' => 'n1', 'timestamp' => 300, 'actor' => 'carol', 'summary' => 'gebeld met melder']],
		];
	}//end threeWrites()

	public function testTheThreeWritesComeBackNewestFirstWithTheirKinds(): void {
		$page = $this->merge->page($this->threeWrites());

		$this->assertSame(['note', 'file', 'audit'], array_column($page['rows'], 'kind'));
		$this->assertSame(['carol', 'bob', 'alice'], array_column($page['rows'], 'actor'));
		$this->assertSame('gebeld met melder', $page['rows'][0]['summary']);
	}//end testTheThreeWritesComeBackNewestFirstWithTheirKinds()

	public function testAPageOfReadsDoesNotBuryOneWrite(): void {
		$audit = [];
		for ($i = 0; $i < 15; $i++) {
			$audit[] = ['id' => 'r' . $i, 'action' => 'read', 'timestamp' => (1000 + $i), 'user' => 'nosy'];
		}

		$audit[] = ['id' => 'w1', 'action' => 'update', 'timestamp' => 500, 'user' => 'alice'];

		$page = $this->merge->page(['audit' => $audit]);

		$this->assertCount(1, $page['rows']);
		$this->assertSame('w1', $page['rows'][0]['id']);
	}//end testAPageOfReadsDoesNotBuryOneWrite()

	public function testTheReadsToggleBringsThemBack(): void {
		$audit = [
			['id' => 'r1', 'action' => 'read', 'timestamp' => 1000, 'user' => 'nosy'],
			['id' => 'w1', 'action' => 'update', 'timestamp' => 500, 'user' => 'alice'],
		];

		$page = $this->merge->page(['audit' => $audit], ['includeReads' => true]);

		$this->assertSame(['r1', 'w1'], array_column($page['rows'], 'id'));
	}//end testTheReadsToggleBringsThemBack()

	public function testExcludingReadsNeverExcludesANote(): void {
		// A note whose action is spelled `read` is still a note. Filtering on
		// the word rather than on the kind empties a chip the reader turned on.
		$page = $this->merge->page(
			['note' => [['id' => 'n1', 'action' => 'read', 'timestamp' => 300, 'summary' => 'gelezen door melder']]]
		);

		$this->assertCount(1, $page['rows']);
		$this->assertSame('note', $page['rows'][0]['kind']);
	}//end testExcludingReadsNeverExcludesANote()

	public function testTheKindChipsNarrowTheFeed(): void {
		$page = $this->merge->page($this->threeWrites(), ['kinds' => ['note', 'file']]);

		$this->assertSame(['note', 'file'], array_column($page['rows'], 'kind'));
	}//end testTheKindChipsNarrowTheFeed()

	public function testTheDateRangeNarrowsTheFeed(): void {
		$page = $this->merge->page($this->threeWrites(), ['from' => 150, 'until' => 250]);

		$this->assertSame(['f1'], array_column($page['rows'], 'id'));
	}//end testTheDateRangeNarrowsTheFeed()

	/**
	 * Two pages hold every row exactly once, which is what a shared cursor is
	 * for and what five offsets cannot do.
	 *
	 * @return void
	 */
	public function testPagingOnTheSharedCursorLosesNoRowAndRepeatsNone(): void {
		$sources = [
			'audit' => [
				['id' => 'a1', 'action' => 'update', 'timestamp' => 500],
				['id' => 'a2', 'action' => 'update', 'timestamp' => 100],
			],
			'note' => [
				['id' => 'n1', 'timestamp' => 400],
				['id' => 'n2', 'timestamp' => 200],
			],
			'mail' => [['id' => 'm1', 'timestamp' => 300]],
		];

		$first = $this->merge->page($sources, ['pageSize' => 3]);
		$this->assertSame(['a1', 'n1', 'm1'], array_column($first['rows'], 'id'));
		$this->assertSame(300, $first['nextCursor']);

		$second = $this->merge->page($sources, ['pageSize' => 3, 'before' => $first['nextCursor']]);
		$this->assertSame(['n2', 'a2'], array_column($second['rows'], 'id'));
		$this->assertNull($second['nextCursor']);

		$seen = array_merge(array_column($first['rows'], 'id'), array_column($second['rows'], 'id'));
		$this->assertSame(['a1', 'n1', 'm1', 'n2', 'a2'], $seen);
		$this->assertSame(count($seen), count(array_unique($seen)));
	}//end testPagingOnTheSharedCursorLosesNoRowAndRepeatsNone()

	public function testATieBreaksTheSameWayEveryTime(): void {
		$sources = [
			'audit' => [['id' => 'a1', 'action' => 'update', 'timestamp' => 100]],
			'note' => [['id' => 'n1', 'timestamp' => 100]],
			'mail' => [['id' => 'm1', 'timestamp' => 100]],
		];

		$first = array_column($this->merge->page($sources)['rows'], 'id');
		$again = array_column($this->merge->page($sources)['rows'], 'id');

		$this->assertSame($first, $again);
		// The declared kind order is the tie-break, so the order is a fact
		// somebody chose rather than whatever the sort happened to do.
		$this->assertSame(['a1', 'n1', 'm1'], $first);
	}//end testATieBreaksTheSameWayEveryTime()

	public function testARowWithNoTimeSortsLastRatherThanFirst(): void {
		$sources = [
			'note' => [['id' => 'undated', 'summary' => 'geen datum']],
			'audit' => [['id' => 'a1', 'action' => 'update', 'timestamp' => 100]],
		];

		$this->assertSame(['a1', 'undated'], array_column($this->merge->page($sources)['rows'], 'id'));
	}//end testARowWithNoTimeSortsLastRatherThanFirst()

	public function testAnIsoMomentIsReadAsAMoment(): void {
		$row = $this->merge->normalise(['id' => 'x', 'created' => '2026-09-18T10:00:00+02:00'], 'note');

		$this->assertSame(strtotime('2026-09-18T10:00:00+02:00'), $row['timestamp']);
	}//end testAnIsoMomentIsReadAsAMoment()

	public function testTheBoundIsPerSourceAndCappedForEverybody(): void {
		$this->assertSame(ActivityFeedMerge::DEFAULT_PAGE_SIZE, $this->merge->boundPerSource());
		$this->assertSame(10, $this->merge->boundPerSource(['pageSize' => 10]));
		// A caller asking for ten thousand rows is asking five sources for ten
		// thousand rows each.
		$this->assertSame(ActivityFeedMerge::MAX_PAGE_SIZE, $this->merge->boundPerSource(['pageSize' => 10000]));
		$this->assertSame(ActivityFeedMerge::DEFAULT_PAGE_SIZE, $this->merge->boundPerSource(['pageSize' => 'veel']));
	}//end testTheBoundIsPerSourceAndCappedForEverybody()

	public function testEverySourceIsCountedSoAnEmptyOneIsVisible(): void {
		$counts = $this->merge->page($this->threeWrites())['counts'];

		$this->assertSame(1, $counts['audit']);
		$this->assertSame(1, $counts['file']);
		$this->assertSame(1, $counts['note']);
		// A source that contributed nothing says zero rather than being
		// absent: a chip with no rows behind it is a different fact from a
		// source that was never asked.
		$this->assertSame(0, $counts['mail']);
		$this->assertSame(0, $counts['activity']);
	}//end testEverySourceIsCountedSoAnEmptyOneIsVisible()

	public function testAnUnknownKindIsNotMerged(): void {
		// The vocabulary is closed and it is the chips' vocabulary: a sixth
		// kind would render a chip nobody can translate.
		$page = $this->merge->page(['gossip' => [['id' => 'g1', 'timestamp' => 900]]]);

		$this->assertSame([], $page['rows']);
	}//end testAnUnknownKindIsNotMerged()

	public function testACursorExcludesTheRowItPointsAt(): void {
		$sources = ['note' => [['id' => 'n1', 'timestamp' => 300], ['id' => 'n2', 'timestamp' => 200]]];

		$page = $this->merge->page($sources, ['before' => 300]);

		// Strictly older: a row exactly on the cursor is the last row of the
		// previous page and would otherwise be shown twice.
		$this->assertSame(['n2'], array_column($page['rows'], 'id'));
	}//end testACursorExcludesTheRowItPointsAt()
}//end class
