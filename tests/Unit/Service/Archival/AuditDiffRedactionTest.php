<?php

declare(strict_types=1);

/**
 * The audit trail keeps the fact and loses the values.
 *
 * 🔴 AN ANONYMISATION THAT LEAVES THE STORED DIFFS ALONE IS A RENAME WITH A
 * RECEIPT. Every write leaves a diff holding the value before and after, so a
 * record whose payload no longer names the citizen still has the name in every
 * historical row of its own trail, shown beside the record it was removed from.
 *
 * What must survive is the FACT: which properties were touched, when, under
 * which profile. What must not is anything a person can be recovered from.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\AnonymisationPlan;
use OCA\OpenRegister\Service\Archival\AuditDiffRedactionTarget;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the audit diff redaction.
 */
class AuditDiffRedactionTest extends TestCase {

	private AuditDiffRedactionTarget $redaction;

	/**
	 * Wire the redaction.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->redaction = new AuditDiffRedactionTarget();
	}//end setUp()

	/**
	 * One stored diff holding a rename of the citizen and a status change.
	 *
	 * @return array<string, mixed> The diff.
	 */
	private function changed(): array {
		return [
			'naam' => ['old' => 'F. El-Amrani', 'new' => 'Fatima El-Amrani'],
			'status' => ['old' => 'open', 'new' => 'gesloten'],
		];
	}//end changed()

	/**
	 * 🔴 BOTH SIDES GO. Keeping `old` because only `new` matched the payload is
	 * exactly how the name survives: the value a record USED to hold is the
	 * value being removed.
	 *
	 * @return void
	 */
	public function testBothSidesOfARedactedPropertyAreRemoved(): void {
		$redacted = $this->redaction->redact(changed: $this->changed(), changedProperties: ['naam']);

		$this->assertSame(AuditDiffRedactionTarget::REDACTED, $redacted['naam']['old']);
		$this->assertSame(AuditDiffRedactionTarget::REDACTED, $redacted['naam']['new']);
		$this->assertStringNotContainsString('El-Amrani', json_encode($redacted));
	}//end testBothSidesOfARedactedPropertyAreRemoved()

	/**
	 * 🔴 THE SHAPE STAYS. The property name and the untouched properties remain,
	 * so the trail still says which fields moved and when. Removing the whole
	 * entry would lose the fact along with the value.
	 *
	 * @return void
	 */
	public function testTheTrailStillSaysWhichFieldsMoved(): void {
		$redacted = $this->redaction->redact(changed: $this->changed(), changedProperties: ['naam']);

		$this->assertArrayHasKey('naam', $redacted);
		$this->assertSame(['old' => 'open', 'new' => 'gesloten'], $redacted['status']);
	}//end testTheTrailStillSaysWhichFieldsMoved()

	/**
	 * A redacted value reads as redacted, not as blank. "Removed by an
	 * anonymisation" and "was empty at the time" are different facts and only
	 * one of them is about the citizen.
	 *
	 * @return void
	 */
	public function testARedactedValueIsMarkedRatherThanEmptied(): void {
		$redacted = $this->redaction->redact(changed: ['naam' => 'Fatima'], changedProperties: ['naam']);

		$this->assertSame(AuditDiffRedactionTarget::REDACTED, $redacted['naam']);
		$this->assertNotSame('', $redacted['naam']);
	}//end testARedactedValueIsMarkedRatherThanEmptied()

	/**
	 * The entry recording the act names the profile and the scope, which is
	 * what an auditor proves the anonymisation with.
	 *
	 * @return void
	 */
	public function testTheEntryRecordsTheActAndItsScope(): void {
		$plan = new AnonymisationPlan(
			objectUuid: 'obj-1',
			profileName: 'zaak-statistiek',
			before: [],
			after: [],
			changedProperties: ['naam'],
			keptProperties: ['zaaknummer'],
			saltFingerprint: 'abc123'
		);

		$entry = $this->redaction->entryFor(plan: $plan);

		$this->assertSame('zaak-statistiek', $entry['anonymisation']['profile']);
		$this->assertSame(['naam'], $entry['anonymisation']['properties']);
		$this->assertSame(['zaaknummer'], $entry['anonymisation']['kept']);
	}//end testTheEntryRecordsTheActAndItsScope()

	/**
	 * 🔴 THE ACT CHECKS ITSELF. A redaction that missed a nested copy would
	 * report success while the name sits one level down, and the audit trail is
	 * the last place anybody would think to look for it.
	 *
	 * @return void
	 */
	public function testALeftoverValueIsFoundRatherThanReportedClean(): void {
		$missed = ['meta' => ['aanvrager' => 'Fatima El-Amrani']];

		$leftovers = $this->redaction->leftovers(redacted: $missed, removed: ['Fatima El-Amrani']);

		$this->assertSame(['Fatima El-Amrani'], $leftovers);
	}//end testALeftoverValueIsFoundRatherThanReportedClean()

	/**
	 * A clean redaction reports clean, so the check above is not a blanket that
	 * fails every act.
	 *
	 * @return void
	 */
	public function testACleanRedactionHasNoLeftovers(): void {
		$redacted = $this->redaction->redact(changed: $this->changed(), changedProperties: ['naam']);

		$this->assertSame([], $this->redaction->leftovers(redacted: $redacted, removed: ['Fatima El-Amrani', 'F. El-Amrani']));
	}//end testACleanRedactionHasNoLeftovers()

	/**
	 * A property the plan does not name is untouched, so the redaction cannot
	 * quietly widen its own scope.
	 *
	 * @return void
	 */
	public function testAPropertyOutsideThePlanIsUntouched(): void {
		$redacted = $this->redaction->redact(changed: $this->changed(), changedProperties: []);

		$this->assertSame($this->changed(), $redacted);
	}//end testAPropertyOutsideThePlanIsUntouched()
}//end class
