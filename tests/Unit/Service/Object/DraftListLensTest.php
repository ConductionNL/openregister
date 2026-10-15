<?php

/**
 * The draft lens on a list: hidden unless asked for, the caller's own kept in view (decision 201).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-list-must-hide-drafts-unless-asked
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DraftListLens;
use PHPUnit\Framework\TestCase;

/**
 * Decision 201: a work list hides drafts by default, with a filter to show them.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\Schema
 */
class DraftListLensTest extends TestCase {

	/**
	 * Nobody asked: drafts are left out. A caller who forgets the parameter gets the work list.
	 */
	public function testNoParameterHidesDrafts(): void {
		$this->assertSame(DraftListLens::EXCLUDE, (new DraftListLens())->modeFor(query: ['_limit' => 20]));
	}//end testNoParameterHidesDrafts()

	/**
	 *  is drafts alone,  is drafts beside the rest, in any casing.
	 *
	 * @param mixed  $value    What arrives over the wire.
	 * @param string $expected The lens.
	 *
	 * @dataProvider askedFor
	 */
	public function testTheParameterPicksTheLens(mixed $value, string $expected): void {
		$this->assertSame($expected, (new DraftListLens())->modeFor(query: ['_drafts' => $value]));
	}//end testTheParameterPicksTheLens()

	/**
	 * The spellings a query string produces.
	 *
	 * An unknown value falls back to hiding: a typo must never widen a list.
	 *
	 * @return array<string, array{0: mixed, 1: string}> The cases.
	 */
	public static function askedFor(): array {
		return [
			'the string true' => ['true', DraftListLens::ONLY],
			'the boolean' => [true, DraftListLens::ONLY],
			'the digit' => ['1', DraftListLens::ONLY],
			'any' => ['any', DraftListLens::ANY],
			'ANY' => [' ANY ', DraftListLens::ANY],
			'none' => ['none', DraftListLens::NONE],
			'none, any case' => [' None ', DraftListLens::NONE],
			'explicit false' => ['false', DraftListLens::EXCLUDE],
			'a typo' => ['al', DraftListLens::EXCLUDE],
			'the empty string' => ['', DraftListLens::EXCLUDE],
			'null' => [null, DraftListLens::EXCLUDE],
			'an array' => [['any'], DraftListLens::EXCLUDE],
		];
	}//end askedFor()

	/**
	 * A caller that filters on the stored status has said which statuses it wants; the default steps aside.
	 *
	 * Without this, `@self[status]=draft` would answer the caller's own drafts
	 * only, and say nothing about the rest it was allowed to see.
	 */
	public function testAnExplicitStatusFilterDecides(): void {
		$lens = new DraftListLens();

		$this->assertSame(DraftListLens::ANY, $lens->modeFor(query: ['@self' => ['status' => 'draft']]));
		$this->assertSame(DraftListLens::ANY, $lens->modeFor(query: ['@self' => ['status' => ['in' => ['draft', 'active']]]]));
		$this->assertSame(DraftListLens::EXCLUDE, $lens->modeFor(query: ['@self' => ['owner' => 'alice']]), 'Another metadata filter is not a status filter.');
		$this->assertSame(DraftListLens::EXCLUDE, $lens->modeFor(query: ['@self' => ['status' => '']]), 'An empty filter asks for nothing.');
		$this->assertSame(DraftListLens::ONLY, $lens->modeFor(query: ['_drafts' => 'true', '@self' => ['status' => 'active']]), 'The parameter, when sent, wins.');
	}//end testAnExplicitStatusFilterDecides()

	/**
	 * The default clause keeps rows that are not drafts, legacy rows without a status included.
	 */
	public function testHidingKeepsEverythingThatIsNotADraft(): void {
		$this->assertSame(
			"(t._status IS NULL OR t._status <> 'draft')",
			(new DraftListLens())->sql(mode: DraftListLens::EXCLUDE, columnPrefix: 't.', owner: null)
		);
	}//end testHidingKeepsEverythingThatIsNotADraft()

	/**
	 * The owner still sees their own drafts: the clause admits the caller's rows, drafts or not.
	 */
	public function testTheOwnerKeepsTheirOwnDrafts(): void {
		$this->assertSame(
			"((_status IS NULL OR _status <> 'draft') OR _owner = 'alice')",
			(new DraftListLens())->sql(mode: DraftListLens::EXCLUDE, columnPrefix: '', owner: "'alice'")
		);
		$this->assertSame(
			"((t._status IS NULL OR t._status <> 'draft') OR t._owner = :dcValue1)",
			(new DraftListLens())->sql(mode: DraftListLens::EXCLUDE, columnPrefix: 't.', owner: ':dcValue1'),
			'The owner is whatever the emitter quoted or bound; the lens never quotes.'
		);
	}//end testTheOwnerKeepsTheirOwnDrafts()

	/**
	 * A schema with `draftsListed: false` keeps the caller's own drafts out of its work list; asking still shows them.
	 */
	public function testASchemaCanKeepOwnDraftsOutOfItsLists(): void {
		$lens = new DraftListLens();
		$quiet = new Schema();
		$quiet->setConfiguration(['draftsListed' => false]);
		$ordinary = new Schema();
		$explicit = new Schema();
		$explicit->setConfiguration(['draftsListed' => true]);

		// The default, and everything that reads as the default.
		$this->assertSame(DraftListLens::NONE, $lens->modeFor(query: [], schema: $quiet));
		$this->assertSame(DraftListLens::NONE, $lens->modeFor(query: ['_drafts' => 'false'], schema: $quiet));
		$this->assertSame(DraftListLens::NONE, $lens->modeFor(query: ['_drafts' => 'al'], schema: $quiet), 'A typo must not widen the list.');

		// Unless the caller asks for drafts.
		$this->assertSame(DraftListLens::ONLY, $lens->modeFor(query: ['_drafts' => 'true'], schema: $quiet));
		$this->assertSame(DraftListLens::ANY, $lens->modeFor(query: ['_drafts' => 'any'], schema: $quiet));
		$this->assertSame(DraftListLens::ANY, $lens->modeFor(query: ['@self' => ['status' => 'draft']], schema: $quiet));

		// Control: a schema that does not say so keeps today's work list.
		$this->assertSame(DraftListLens::EXCLUDE, $lens->modeFor(query: [], schema: $ordinary));
		$this->assertSame(DraftListLens::EXCLUDE, $lens->modeFor(query: [], schema: $explicit));
		$this->assertSame(DraftListLens::EXCLUDE, $lens->modeFor(query: []));
	}//end testASchemaCanKeepOwnDraftsOutOfItsLists()

	/**
	 * `_drafts=none` leaves out every draft, the caller's own included (decision 216).
	 *
	 * The control is the test above: the same caller on the default lens
	 * keeps their own drafts, so this clause differs only by the owner admit.
	 */
	public function testNoneLeavesOutTheCallersOwnDraftsToo(): void {
		$lens = new DraftListLens();

		$this->assertSame(
			"(t._status IS NULL OR t._status <> 'draft')",
			$lens->sql(mode: DraftListLens::NONE, columnPrefix: 't.', owner: ':dcValue1')
		);
		$this->assertSame("(_status IS NULL OR _status <> 'draft')", $lens->sql(mode: DraftListLens::NONE, columnPrefix: '', owner: "'alice'"));
		$this->assertSame(DraftListLens::NONE, $lens->modeFor(query: ['_drafts' => 'none', '@self' => ['status' => 'draft']]), 'The parameter, when sent, wins.');
	}//end testNoneLeavesOutTheCallersOwnDraftsToo()

	/**
	 * Drafts alone, and no clause at all when both were asked for.
	 *
	 * The second is the control: a lens that always emitted a clause would
	 * pass the two tests above and still hide drafts from a caller who asked.
	 */
	public function testOnlyAndAny(): void {
		$lens = new DraftListLens();

		$this->assertSame("t._status = 'draft'", $lens->sql(mode: DraftListLens::ONLY, columnPrefix: 't.', owner: "'alice'"));
		$this->assertNull($lens->sql(mode: DraftListLens::ANY, columnPrefix: 't.', owner: "'alice'"));
	}//end testOnlyAndAny()
}//end class
