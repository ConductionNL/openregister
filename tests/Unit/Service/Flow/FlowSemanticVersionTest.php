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
	 * The preflight names the removed step AND the removed edge.
	 *
	 * The scenario the requirement is written against. A preview that says
	 * "major" without saying what went is the version of this feature that
	 * teaches an author to stop reading it.
	 *
	 * @return void
	 */
	public function testThePreviewNamesTheRemovedStepAndTheRemovedEdge(): void {
		$published = [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'middle', 'type' => 'openregister.set-fields', 'config' => []],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'middle'],
				['id' => 'e2', 'from' => 'middle', 'to' => 'done'],
			],
		];

		$candidate = [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [['id' => 'e1', 'from' => 'start', 'to' => 'done']],
		];

		$preview = $this->semver->preview(
			publishedGraph: $published,
			candidateGraph: $candidate,
			previousSemver: '1.4.0'
		);

		$this->assertSame(FlowGraphDiff::MAJOR, $preview['verdict']);
		$this->assertSame('2.0.0', $preview['next'], 'the preview says the number, not just the component');
		$this->assertFalse($preview['first']);

		// BOTH, named. The step and the connection are separate losses to a
		// consumer, and reporting only the step would hide a rewiring that
		// removed a path while keeping every node.
		$this->assertContains('middle', $preview['removedNodes']);
		$this->assertStringContainsString('middle', $preview['removed']);
		$this->assertNotSame([], $preview['removedEdges'], 'the removed connection must be named too');
		$this->assertStringContainsString('start', implode(' ', $preview['removedEdges']));
	}//end testThePreviewNamesTheRemovedStepAndTheRemovedEdge()

	/**
	 * An addition-only preview is minor and claims no removal.
	 *
	 * @return void
	 */
	public function testAnAdditionOnlyPreviewIsMinorAndNamesNothing(): void {
		$published = [
			'nodes' => [['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []]],
			'edges' => [],
		];

		$candidate = [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'extra', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [['id' => 'e1', 'from' => 'start', 'to' => 'extra']],
		];

		$preview = $this->semver->preview(
			publishedGraph: $published,
			candidateGraph: $candidate,
			previousSemver: '2.1.0'
		);

		$this->assertSame(FlowGraphDiff::MINOR, $preview['verdict']);
		$this->assertSame('2.2.0', $preview['next']);
		$this->assertSame('', $preview['removed'], 'nothing was removed, so nothing is claimed');
	}//end testAnAdditionOnlyPreviewIsMinorAndNamesNothing()

	/**
	 * With nothing published, the preview says so rather than inventing a diff.
	 *
	 * @return void
	 */
	public function testAPreviewWithNothingPublishedIsTheFirstVersion(): void {
		$preview = $this->semver->preview(
			publishedGraph: null,
			candidateGraph: ['nodes' => [['id' => 'a', 'config' => []]], 'edges' => []],
			previousSemver: null
		);

		$this->assertTrue($preview['first']);
		$this->assertSame(FlowSemanticVersion::FIRST, $preview['next']);
		$this->assertSame([], $preview['removedNodes'], 'a first publish removes nothing from nothing');
	}//end testAPreviewWithNothingPublishedIsTheFirstVersion()

	/**
	 * The preview ASKS; it never refuses.
	 *
	 * An author has to be able to find out that a publish is major without
	 * first asserting that it is not.
	 *
	 * @return void
	 */
	public function testThePreviewNeverRefuses(): void {
		$published = [
			'nodes' => [['id' => 'gone', 'config' => ['k' => 1]]],
			'edges' => [],
		];

		$preview = $this->semver->preview(
			publishedGraph: $published,
			candidateGraph: ['nodes' => [], 'edges' => []],
			previousSemver: '1.0.0'
		);

		$this->assertSame(FlowGraphDiff::MAJOR, $preview['verdict']);
	}//end testThePreviewNeverRefuses()

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
