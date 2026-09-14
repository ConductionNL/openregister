<?php

/**
 * The throttle that keeps the summary off the hot path, and what it may never
 * drop.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use DateTime;
use OCA\OpenRegister\Db\RuleRunSummary;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A rule about dropping writes, exercised without a database.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class RuleSummaryThrottleTest extends TestCase {

	/**
	 * A stored summary at a known moment and verdict.
	 *
	 * @param string $verdict The last verdict.
	 * @param string $lastRun When it last ran.
	 *
	 * @return RuleRunSummary The summary, with an id so it counts as stored.
	 */
	private function stored(string $verdict, string $lastRun): RuleRunSummary {
		$summary = new RuleRunSummary();
		$summary->setId(7);
		$summary->setRuleId('calculation:bezwaar:uiterlijkeDatum');
		$summary->setSchemaSlug('bezwaar');
		$summary->setLastVerdict($verdict);
		$summary->setLastRun(new DateTime($lastRun));

		return $summary;

	}//end stored()

	/**
	 * The mapper, over a connection it never reaches on this path.
	 *
	 * @return RuleRunSummaryMapper The mapper.
	 */
	private function mapper(): RuleRunSummaryMapper {
		return new RuleRunSummaryMapper($this->createMock(IDBConnection::class));

	}//end mapper()

	/**
	 * A repeat of the same verdict inside the window is not written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testARepeatInsideTheWindowIsDropped(): void {
		$this->assertFalse(
			$this->mapper()->worthWriting(
				summary: $this->stored(verdict: RuleVocabulary::VERDICT_FIRED, lastRun: '2026-09-14 12:00:00'),
				verdict: RuleVocabulary::VERDICT_FIRED,
				at: new DateTime('2026-09-14 12:00:30'),
				isError: false
			)
		);

	}//end testARepeatInsideTheWindowIsDropped()

	/**
	 * The same verdict past the window is written, so "last run" stays
	 * accurate to the window rather than drifting indefinitely.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testTheSameVerdictPastTheWindowIsWritten(): void {
		$this->assertTrue(
			$this->mapper()->worthWriting(
				summary: $this->stored(verdict: RuleVocabulary::VERDICT_FIRED, lastRun: '2026-09-14 12:00:00'),
				verdict: RuleVocabulary::VERDICT_FIRED,
				at: new DateTime('2026-09-14 12:02:00'),
				isError: false
			)
		);

	}//end testTheSameVerdictPastTheWindowIsWritten()

	/**
	 * A CHANGED verdict is always written, however recent the last one. This
	 * is the throttle's whole safety property: the moment a rule starts
	 * refusing is the moment an administrator needs to see.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAChangedVerdictIsNeverDropped(): void {
		$this->assertTrue(
			$this->mapper()->worthWriting(
				summary: $this->stored(verdict: RuleVocabulary::VERDICT_FIRED, lastRun: '2026-09-14 12:00:00'),
				verdict: RuleVocabulary::VERDICT_REFUSED,
				at: new DateTime('2026-09-14 12:00:01'),
				isError: false
			)
		);

	}//end testAChangedVerdictIsNeverDropped()

	/**
	 * An error is always written, even repeating the same verdict one second
	 * later, because the error message is the thing being kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAnErrorIsNeverDropped(): void {
		$this->assertTrue(
			$this->mapper()->worthWriting(
				summary: $this->stored(verdict: RuleVocabulary::VERDICT_ERROR, lastRun: '2026-09-14 12:00:00'),
				verdict: RuleVocabulary::VERDICT_ERROR,
				at: new DateTime('2026-09-14 12:00:01'),
				isError: true
			)
		);

	}//end testAnErrorIsNeverDropped()

	/**
	 * A rule's first evaluation always opens its summary.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testAFirstEvaluationIsAlwaysWritten(): void {
		$this->assertTrue(
			$this->mapper()->worthWriting(
				summary: new RuleRunSummary(),
				verdict: RuleVocabulary::VERDICT_FIRED,
				at: new DateTime('2026-09-14 12:00:00'),
				isError: false
			)
		);

	}//end testAFirstEvaluationIsAlwaysWritten()

	/**
	 * The id the hot paths derive is the id the descriptor publishes. Two
	 * derivations of one id is how a run log stops matching its inventory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function testTheDerivedIdMatchesTheDescriptorsOwn(): void {
		$descriptor = new RuleDescriptor(
			kind: RuleVocabulary::KIND_CALCULATION,
			schemaSlug: 'bezwaar',
			key: 'uiterlijkeDatum',
			label: 'uiterlijkeDatum',
			source: 'x-openregister-calculations.uiterlijkeDatum'
		);

		$this->assertSame(
			$descriptor->getId(),
			RuleDescriptor::idFor(
				kind: RuleVocabulary::KIND_CALCULATION,
				schemaSlug: 'bezwaar',
				key: 'uiterlijkeDatum'
			)
		);
		$this->assertSame('calculation:bezwaar:uiterlijkeDatum', $descriptor->getId());

	}//end testTheDerivedIdMatchesTheDescriptorsOwn()
}//end class
