<?php

/**
 * Who saw the BSN (ledger row 5.6).
 *
 * Field-level security hides a property from users outside its group, and a
 * DENIAL is already logged — at debug level, in a place nobody reads. A REVEAL
 * was recorded nowhere at all, and the reveal is the thing a data protection
 * officer asks about.
 *
 * Every test here is a way the record could be wrong while the audit page still
 * shows rows:
 *
 *  - 🔴 deduplicating too widely. A list of forty objects reveals forty times
 *    and that count IS the finding — forty citizens' numbers on one screen. A
 *    collector keyed on (user, property) alone would collapse it to one and
 *    lose exactly the number the officer came for;
 *  - deduplicating too narrowly, so a re-render inside one request counts as a
 *    second look;
 *  - recording a reveal that cannot name the user, the object or the property,
 *    which puts a row in the trail that no question can reach;
 *  - `take()` leaving its rows behind, so the next flush writes them again;
 *  - an unbounded collection, where a bulk export assembles a hundred thousand
 *    entries in memory to describe one act.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\RevealCollector;
use PHPUnit\Framework\TestCase;

/**
 * Pins what a reveal is, how many there are, and when there are none.
 */
class RevealCollectorTest extends TestCase {

	private RevealCollector $collector;

	/**
	 * Set up the collector.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->collector = new RevealCollector();
	}//end setUp()

	/**
	 * Only an explicit `audit: true` asks for a record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testOnlyAnExplicitTrueIsAudited(): void {
		$this->assertTrue($this->collector->isAudited([RevealCollector::AUDIT_KEY => true]));

		$this->assertFalse($this->collector->isAudited(null));
		$this->assertFalse($this->collector->isAudited([]));
		$this->assertFalse($this->collector->isAudited(['read' => [['group' => 'g']]]));
		$this->assertFalse(
			$this->collector->isAudited([RevealCollector::AUDIT_KEY => 'true']),
			'a string is not a declaration'
		);
		$this->assertFalse($this->collector->isAudited([RevealCollector::AUDIT_KEY => 1]));
	}//end testOnlyAnExplicitTrueIsAudited()

	/**
	 * One look is one entry, naming who, what and which object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testOneLookIsOneEntry(): void {
		$this->collector->record('alice', 'object-1', 'bsn', 24, 14);

		$entries = $this->collector->take();

		$this->assertCount(1, $entries);
		$this->assertSame(RevealCollector::ACTION, $entries[0]['action']);
		$this->assertSame('alice', $entries[0]['user']);
		$this->assertSame('object-1', $entries[0]['object']);
		$this->assertSame('bsn', $entries[0]['property']);
		$this->assertSame(24, $entries[0]['schema']);
	}//end testOneLookIsOneEntry()

	/**
	 * 🔴 A list of forty reveals forty times, and that count is the finding.
	 *
	 * The assertion this change turns on. A collector keyed on (user, property)
	 * alone would answer one, and "one person looked at a BSN today" is a
	 * different and much more comfortable fact than "one person looked at
	 * forty".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testAListOfFortyRevealsFortyTimes(): void {
		for ($i = 0; $i < 40; $i++) {
			$this->collector->record('alice', 'object-' . $i, 'bsn');
		}

		$this->assertSame(40, $this->collector->count());
		$this->assertCount(40, $this->collector->take());
	}//end testAListOfFortyRevealsFortyTimes()

	/**
	 * The same field of the same object in one request is one look.
	 *
	 * The other side of the identity: a re-render, or a property read twice on
	 * one path, is not a second look.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testTheSameFieldOfTheSameObjectIsOneLook(): void {
		$this->collector->record('alice', 'object-1', 'bsn');
		$this->collector->record('alice', 'object-1', 'bsn');
		$this->collector->record('alice', 'object-1', 'bsn');

		$this->assertSame(1, $this->collector->count());
	}//end testTheSameFieldOfTheSameObjectIsOneLook()

	/**
	 * Two people, two properties and two objects are all distinct looks.
	 *
	 * The control for the deduplication: one that keyed on the object alone
	 * would satisfy the test above and lose three of these four.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testEachAxisOfTheIdentityCounts(): void {
		$this->collector->record('alice', 'object-1', 'bsn');
		$this->collector->record('bob', 'object-1', 'bsn');
		$this->collector->record('alice', 'object-2', 'bsn');
		$this->collector->record('alice', 'object-1', 'gdprClassification');

		$this->assertSame(4, $this->collector->count());
	}//end testEachAxisOfTheIdentityCounts()

	/**
	 * A reveal that cannot name all three parts records nothing.
	 *
	 * A row that answers none of "who saw what, on which object" is a row no
	 * question can reach.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testAnUnnamedRevealRecordsNothing(): void {
		$this->collector->record('', 'object-1', 'bsn');
		$this->collector->record('alice', '', 'bsn');
		$this->collector->record('alice', 'object-1', '');

		$this->assertSame(0, $this->collector->count());
		$this->assertSame([], $this->collector->take());
	}//end testAnUnnamedRevealRecordsNothing()

	/**
	 * 🔴 `take()` empties the collector.
	 *
	 * A collector that still held its rows after a flush would write them again
	 * on the next one, so a long-running request would multiply every reveal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testTakingEmptiesTheCollector(): void {
		$this->collector->record('alice', 'object-1', 'bsn');

		$this->assertCount(1, $this->collector->take());
		$this->assertSame(0, $this->collector->count());
		$this->assertSame([], $this->collector->take());
	}//end testTakingEmptiesTheCollector()

	/**
	 * The collection is bounded, and says so rather than stopping quietly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testTheCollectionIsBoundedAndSaysSo(): void {
		$this->assertFalse($this->collector->overflowed());

		for ($i = 0; $i <= RevealCollector::MAX_PER_REQUEST; $i++) {
			$this->collector->record('alice', 'object-' . $i, 'bsn');
		}

		$this->assertSame(RevealCollector::MAX_PER_REQUEST, $this->collector->count());
		$this->assertTrue(
			$this->collector->overflowed(),
			'a trail that is quietly incomplete is worse than one that says where it stopped'
		);
	}//end testTheCollectionIsBoundedAndSaysSo()

	/**
	 * A trusted run writes ONE entry naming the process, not one per row.
	 *
	 * D-3. An export job reads every object; an entry per row would swamp the
	 * chain with a fact that has a better name, and the officer's question
	 * about a job is which job ran.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testATrustedRunWritesOneEntry(): void {
		$this->collector->recordProcess('retention-sweep', 'run-7', 120000);
		$this->collector->recordProcess('retention-sweep', 'run-7', 120000);

		$entries = $this->collector->take();

		$this->assertCount(1, $entries);
		$this->assertSame('retention-sweep', $entries[0]['process']);
		$this->assertSame('run-7', $entries[0]['run']);
		$this->assertSame(120000, $entries[0]['revealed']);
	}//end testATrustedRunWritesOneEntry()

	/**
	 * Two runs of one process are two entries.
	 *
	 * The control for the process entry: one keyed on the process alone would
	 * report one sweep however many ran.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testTwoRunsAreTwoEntries(): void {
		$this->collector->recordProcess('retention-sweep', 'run-7', 10);
		$this->collector->recordProcess('retention-sweep', 'run-8', 10);

		$this->assertSame(2, $this->collector->count());
	}//end testTwoRunsAreTwoEntries()

	/**
	 * A process with no name records nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testAnUnnamedProcessRecordsNothing(): void {
		$this->collector->recordProcess('', 'run-7', 10);

		$this->assertSame(0, $this->collector->count());
	}//end testAnUnnamedProcessRecordsNothing()
}//end class
