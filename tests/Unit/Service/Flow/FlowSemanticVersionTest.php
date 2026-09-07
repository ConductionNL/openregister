<?php

/**
 * What the next version is called, and who is allowed to argue with the diff.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowGraphDiff;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Tests for {@see FlowSemanticVersion}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowSemanticVersion
 * @uses \OCA\OpenRegister\Service\Flow\FlowGraphDiff
 */
class FlowSemanticVersionTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var FlowSemanticVersion
	 */
	private FlowSemanticVersion $semver;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->semver = new FlowSemanticVersion();

	}//end setUp()

	/**
	 * The first publish is 1.0.0, whatever the diff says.
	 *
	 * Calling a first publish `2.0.0` because it "removed" everything from
	 * nothing would be arithmetic rather than meaning.
	 *
	 * @return void
	 */
	public function testTheFirstPublishIsOneZeroZeroWhateverTheDiffSaid(): void {
		$this->assertSame('1.0.0', $this->semver->next(previous: null, verdict: FlowGraphDiff::MINOR));
		$this->assertSame('1.0.0', $this->semver->next(previous: null, verdict: FlowGraphDiff::MAJOR));
		$this->assertSame('1.0.0', $this->semver->next(previous: '', verdict: FlowGraphDiff::MAJOR));
	}//end testTheFirstPublishIsOneZeroZeroWhateverTheDiffSaid()

	/**
	 * Major resets the minor; minor keeps the major.
	 *
	 * @return void
	 */
	public function testMajorResetsTheMinorAndMinorKeepsTheMajor(): void {
		$this->assertSame('3.0.0', $this->semver->next(previous: '2.7.0', verdict: FlowGraphDiff::MAJOR));
		$this->assertSame('2.8.0', $this->semver->next(previous: '2.7.0', verdict: FlowGraphDiff::MINOR));
	}//end testMajorResetsTheMinorAndMinorKeepsTheMajor()

	/**
	 * 🔴 Patch stays zero. A graph cannot distinguish a fix from a feature,
	 * and three digits look more precise than two.
	 *
	 * @return void
	 */
	public function testPatchIsAlwaysZero(): void {
		$this->assertStringEndsWith('.0', $this->semver->next(previous: '1.4.9', verdict: FlowGraphDiff::MINOR));
		$this->assertSame('2.0.0', $this->semver->next(previous: '1.4.9', verdict: FlowGraphDiff::MAJOR));
	}//end testPatchIsAlwaysZero()

	/**
	 * An unreadable stored value is reported as absent, not reset silently.
	 *
	 * @return void
	 */
	public function testAnUnreadableVersionFallsBackToTheFirst(): void {
		$this->assertSame('1.0.0', $this->semver->next(previous: 'v2', verdict: FlowGraphDiff::MINOR));
		$this->assertSame('1.0.0', $this->semver->next(previous: '2.1', verdict: FlowGraphDiff::MINOR));
	}//end testAnUnreadableVersionFallsBackToTheFirst()

	/**
	 * No request: the diff stands.
	 *
	 * @return void
	 */
	public function testWithoutARequestTheDiffStands(): void {
		$this->assertSame(FlowGraphDiff::MINOR, $this->semver->reconcile(derived: FlowGraphDiff::MINOR, requested: null));
		$this->assertSame(FlowGraphDiff::MAJOR, $this->semver->reconcile(derived: FlowGraphDiff::MAJOR, requested: ''));
	}//end testWithoutARequestTheDiffStands()

	/**
	 * An author may RAISE a minor to a major: they know which values a
	 * consumer reads, and the diff does not.
	 *
	 * @return void
	 */
	public function testAnAuthorMayRaiseAMinorToAMajor(): void {
		$this->assertSame(
			FlowGraphDiff::MAJOR,
			$this->semver->reconcile(derived: FlowGraphDiff::MINOR, requested: 'major')
		);
	}//end testAnAuthorMayRaiseAMinorToAMajor()

	/**
	 * 🔴 An author may NOT talk a removal down, and the refusal names what
	 * went. Evidence can be added to, never argued down.
	 *
	 * @return void
	 */
	public function testAnAuthorCannotTalkARemovalDown(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('This removes steps b.');

		$this->semver->reconcile(
			derived: FlowGraphDiff::MAJOR,
			requested: 'minor',
			removed: 'This removes steps b.'
		);
	}//end testAnAuthorCannotTalkARemovalDown()

	/**
	 * The refusal still says something when the caller passed no summary.
	 *
	 * @return void
	 */
	public function testTheRefusalIsNeverJustCannotBeMinor(): void {
		try {
			$this->semver->reconcile(derived: FlowGraphDiff::MAJOR, requested: 'minor');
			$this->fail('expected a refusal');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('removed', $e->getMessage());
		}
	}//end testTheRefusalIsNeverJustCannotBeMinor()

	/**
	 * A word that is neither is refused rather than silently ignored.
	 *
	 * @return void
	 */
	public function testAnUnknownRequestIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->semver->reconcile(derived: FlowGraphDiff::MINOR, requested: 'patch');
	}//end testAnUnknownRequestIsRefused()

	/**
	 * The back-fill's sequence is minor throughout, because it does not know.
	 *
	 * @return void
	 */
	public function testTheBackfillCountsMinorsBecauseItDoesNotKnow(): void {
		$this->assertSame('1.0.0', $this->semver->backfilled(ordinalPosition: 0));
		$this->assertSame('1.3.0', $this->semver->backfilled(ordinalPosition: 3));
		$this->assertSame('1.0.0', $this->semver->backfilled(ordinalPosition: -2), 'a negative position is still the first');
	}//end testTheBackfillCountsMinorsBecauseItDoesNotKnow()
}//end class
