<?php

/**
 * Unit tests for a saved view's count alert.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\View;

use InvalidArgumentException;
use OCA\OpenRegister\Service\View\ViewAlert;
use PHPUnit\Framework\TestCase;

class ViewAlertTest extends TestCase {

	/**
	 * A declared alert: twenty open cases, told to the team lead.
	 *
	 * @param array $overrides Fields to replace.
	 *
	 * @return ViewAlert
	 */
	private function alert(array $overrides = []): ViewAlert {
		$alert = ViewAlert::parse(
			array_merge(
				['operator' => 'gte', 'threshold' => 20, 'recipients' => ['teamlead'], 'every' => 900],
				$overrides
			)
		);

		$this->assertNotNull($alert);
		return $alert;
	}//end alert()

	/**
	 * No alert declared is no alert, not an empty one.
	 *
	 * @return void
	 */
	public function testAViewWithoutAnAlertHasNone(): void {
		$this->assertNull(ViewAlert::parse(null));
		$this->assertNull(ViewAlert::parse([]));
	}//end testAViewWithoutAnAlertHasNone()

	/**
	 * A declared alert reads, and fills in the channel it did not name.
	 *
	 * @return void
	 */
	public function testADeclaredAlertReads(): void {
		$alert = $this->alert();

		$this->assertSame('gte', $alert->operator);
		$this->assertSame(20, $alert->threshold);
		$this->assertSame(['teamlead'], $alert->recipients);
		$this->assertSame(['nc-notification'], $alert->channels);
	}//end testADeclaredAlertReads()

	/**
	 * Every refusal names its field.
	 *
	 * A 422 that does not say what to fix sends somebody back to a form with
	 * five inputs and no idea which one.
	 *
	 * @return void
	 */
	public function testEveryRefusalNamesItsField(): void {
		$cases = [
			'alert.operator' => ['operator' => 'above', 'threshold' => 10, 'recipients' => ['a']],
			'alert.threshold' => ['operator' => 'gte', 'threshold' => '10', 'recipients' => ['a']],
			'alert.recipients' => ['operator' => 'gte', 'threshold' => 10, 'recipients' => []],
			'alert.every' => ['operator' => 'gte', 'threshold' => 10, 'recipients' => ['a'], 'every' => 10],
		];

		foreach ($cases as $field => $bad) {
			try {
				ViewAlert::parse($bad);
				$this->fail('accepted ' . $field);
			} catch (InvalidArgumentException $refused) {
				$this->assertStringContainsString($field, $refused->getMessage());
			}
		}
	}//end testEveryRefusalNamesItsField()

	/**
	 * A negative threshold is refused: a count cannot be below zero, so the
	 * alert would fire on every sweep forever.
	 *
	 * @return void
	 */
	public function testANegativeThresholdIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		ViewAlert::parse(['operator' => 'gte', 'threshold' => -1, 'recipients' => ['a']]);
	}//end testANegativeThresholdIsRefused()

	/**
	 * 🔴 A STANDING BACKLOG PAGES ONCE. The scenario this design exists for:
	 * eight sweeps over two hours with the count above the line send one
	 * notification, not eight.
	 *
	 * @return void
	 */
	public function testAStandingBacklogPagesOnce(): void {
		$alert = $this->alert();
		$state = ViewAlert::ARMED;
		$fired = 0;

		for ($sweep = 0; $sweep < 8; $sweep++) {
			$decision = $alert->decide(state: $state, count: 23);
			$state = $decision['state'];
			if ($decision['fires'] === true) {
				$fired++;
			}
		}

		$this->assertSame(1, $fired, 'eight sweeps, one notification');
		$this->assertSame(ViewAlert::FIRED, $state);
	}//end testAStandingBacklogPagesOnce()

	/**
	 * The alert re-arms when the backlog clears, and fires again next time.
	 *
	 * @return void
	 */
	public function testItReArmsWhenTheCountComesBack(): void {
		$alert = $this->alert();

		$cleared = $alert->decide(state: ViewAlert::FIRED, count: 12);
		$this->assertSame(ViewAlert::ARMED, $cleared['state']);
		$this->assertFalse($cleared['fires'], 'nobody asked to hear that a backlog cleared');

		$again = $alert->decide(state: $cleared['state'], count: 21);
		$this->assertTrue($again['fires']);
	}//end testItReArmsWhenTheCountComesBack()

	/**
	 * `lte` is the mirror, not the negation: it fires when the count FALLS to
	 * the line, and re-arms when it rises.
	 *
	 * @return void
	 */
	public function testLteFiresOnTheWayDown(): void {
		$alert = $this->alert(['operator' => 'lte', 'threshold' => 3]);

		$this->assertTrue($alert->decide(state: ViewAlert::ARMED, count: 2)['fires']);
		$this->assertFalse($alert->decide(state: ViewAlert::FIRED, count: 2)['fires']);
		$this->assertSame(ViewAlert::ARMED, $alert->decide(state: ViewAlert::FIRED, count: 9)['state']);
	}//end testLteFiresOnTheWayDown()

	/**
	 * The threshold is inclusive on both operators: `gte 20` fires at exactly
	 * twenty, which is what somebody typing "twenty or more" means.
	 *
	 * @return void
	 */
	public function testTheThresholdIsInclusive(): void {
		$this->assertTrue($this->alert()->isCrossed(count: 20));
		$this->assertFalse($this->alert()->isCrossed(count: 19));
		$this->assertTrue($this->alert(['operator' => 'lte', 'threshold' => 3])->isCrossed(count: 3));
	}//end testTheThresholdIsInclusive()

	/**
	 * A view never evaluated is due now.
	 *
	 * An alert somebody set five minutes ago should not wait out an interval
	 * it has no record of.
	 *
	 * @return void
	 */
	public function testAViewNeverEvaluatedIsDue(): void {
		$this->assertTrue($this->alert()->isDue(lastEvaluated: null, now: 1_800_000_000));
	}//end testAViewNeverEvaluatedIsDue()

	/**
	 * A view evaluated inside its interval is not due.
	 *
	 * @return void
	 */
	public function testAViewInsideItsIntervalIsNotDue(): void {
		$now = 1_800_000_000;

		$this->assertFalse($this->alert()->isDue(lastEvaluated: ($now - 100), now: $now));
		$this->assertTrue($this->alert()->isDue(lastEvaluated: ($now - 900), now: $now));
	}//end testAViewInsideItsIntervalIsNotDue()
}//end class
