<?php

/**
 * DescriptorFold decides, per flattened path, what the merged definition and the next baseline carry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Service\ShippedBaseline\AuthorizationBlocks;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorFold;
use OCA\OpenRegister\Service\ShippedBaseline\DescriptorParts;
use OCA\OpenRegister\Service\ShippedBaseline\DivergenceComparator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Every divergence state, decided and undecided, for plain paths and authorization block members.
 */
#[CoversClass(DescriptorFold::class)]
#[UsesClass(AuthorizationBlocks::class)]
#[UsesClass(DescriptorParts::class)]
class DescriptorFoldTest extends TestCase {

	private const SOURCES = [
		'baseline' => ['title' => 'Old', 'gone' => 'b-gone', 'authorization.read.0' => 'b-auth'],
		'incoming' => ['title' => 'New', 'added' => 'i-added', 'authorization.read.0' => 'i-auth'],
		'live' => ['title' => 'Mine', 'gone' => 'l-gone', 'authorization.read.0' => 'l-auth'],
	];

	/**
	 * @param array<int, string>                                $decisions Decided paths.
	 * @param array<string, array{state: string, decided: bool}> $blocks    Block states by root.
	 */
	private function fold(array $decisions = [], array $blocks = []): DescriptorFold {
		return new DescriptorFold(
			sources: self::SOURCES,
			decisions: $decisions,
			blocks: $blocks,
			authorization: new AuthorizationBlocks(parts: new DescriptorParts())
		);
	}//end fold()

	public function testUpstreamPathTakesTheIncomingValueAndIsApplied(): void {
		$fold = $this->fold();
		$fold->fold(path: 'title', state: DivergenceComparator::UPSTREAM);

		$this->assertSame(['title' => 'New'], $fold->mergedParts);
		$this->assertSame(['title' => 'New'], $fold->nextBaseline);
		$this->assertSame(['title'], $fold->applied);
		$this->assertSame([], $fold->preserved);
		$this->assertSame([], $fold->conflicts);
	}//end testUpstreamPathTakesTheIncomingValueAndIsApplied()

	public function testUpstreamRemovalDropsThePathFromBothAndIsApplied(): void {
		$fold = $this->fold();
		$fold->fold(path: 'gone', state: DivergenceComparator::UPSTREAM);

		$this->assertSame([], $fold->mergedParts);
		$this->assertSame([], $fold->nextBaseline);
		$this->assertSame(['gone'], $fold->applied);
	}//end testUpstreamRemovalDropsThePathFromBothAndIsApplied()

	public function testLocalPathKeepsTheLiveValueAndTheOldBaseline(): void {
		$fold = $this->fold();
		$fold->fold(path: 'gone', state: DivergenceComparator::LOCAL);

		$this->assertSame(['gone' => 'l-gone'], $fold->mergedParts);
		$this->assertSame(['gone' => 'b-gone'], $fold->nextBaseline);
		$this->assertSame(['gone'], $fold->preserved);
		$this->assertSame([], $fold->applied);
	}//end testLocalPathKeepsTheLiveValueAndTheOldBaseline()

	public function testLocalRemovalIsStillRecordedAsPreserved(): void {
		$fold = $this->fold();
		$fold->fold(path: 'added', state: DivergenceComparator::LOCAL);

		$this->assertSame([], $fold->mergedParts);
		$this->assertSame([], $fold->nextBaseline);
		$this->assertSame(['added'], $fold->preserved);
	}//end testLocalRemovalIsStillRecordedAsPreserved()

	public function testUndecidedConflictKeepsLiveAndBaselineAndIsReported(): void {
		$fold = $this->fold();
		$fold->fold(path: 'title', state: DivergenceComparator::BOTH);

		$this->assertSame(['title' => 'Mine'], $fold->mergedParts);
		$this->assertSame(['title' => 'Old'], $fold->nextBaseline);
		$this->assertSame([], $fold->applied);
		$this->assertSame(
			[
				[
					'path' => 'title',
					'shipped' => 'New',
					'live' => 'Mine',
					'shippedPresent' => true,
					'livePresent' => true,
				],
			],
			$fold->conflicts
		);
	}//end testUndecidedConflictKeepsLiveAndBaselineAndIsReported()

	public function testConflictOnAPathOneSideRemovedReportsWhichSideIsPresent(): void {
		$fold = $this->fold();
		$fold->fold(path: 'added', state: DivergenceComparator::BOTH);

		$this->assertSame([], $fold->mergedParts);
		$this->assertSame(
			[['path' => 'added', 'shipped' => 'i-added', 'live' => null, 'shippedPresent' => true, 'livePresent' => false]],
			$fold->conflicts
		);
	}//end testConflictOnAPathOneSideRemovedReportsWhichSideIsPresent()

	public function testDecidedConflictIsTakenFromUpstreamAndAppliedOnce(): void {
		$fold = $this->fold(decisions: ['title']);
		$fold->fold(path: 'title', state: DivergenceComparator::BOTH);

		$this->assertSame(['title' => 'New'], $fold->mergedParts);
		$this->assertSame(['title' => 'New'], $fold->nextBaseline);
		$this->assertSame(['title'], $fold->applied);
		$this->assertSame([], $fold->conflicts);
	}//end testDecidedConflictIsTakenFromUpstreamAndAppliedOnce()

	public function testDecidedUpstreamPathIsNotListedAsApplied(): void {
		$fold = $this->fold(decisions: ['title']);
		$fold->fold(path: 'title', state: DivergenceComparator::UPSTREAM);

		$this->assertSame(['title' => 'New'], $fold->mergedParts);
		$this->assertSame([], $fold->applied);
	}//end testDecidedUpstreamPathIsNotListedAsApplied()

	public function testSettledStatesKeepLiveAndMoveTheBaselineToIncoming(): void {
		foreach ([DivergenceComparator::UNCHANGED, DivergenceComparator::CONVERGED] as $state) {
			$fold = $this->fold();
			$fold->fold(path: 'title', state: $state);

			$this->assertSame(['title' => 'Mine'], $fold->mergedParts, $state);
			$this->assertSame(['title' => 'New'], $fold->nextBaseline, $state);
			$this->assertSame([], $fold->applied, $state);
			$this->assertSame([], $fold->preserved, $state);
		}
	}//end testSettledStatesKeepLiveAndMoveTheBaselineToIncoming()

	public function testSettledPathAbsentEverywhereWritesNothing(): void {
		$fold = $this->fold();
		$fold->fold(path: 'nowhere', state: DivergenceComparator::UNCHANGED);

		$this->assertSame([], $fold->mergedParts);
		$this->assertSame([], $fold->nextBaseline);
	}//end testSettledPathAbsentEverywhereWritesNothing()

	public function testAuthorizationPathWithoutABlockFoldsAsItsOwnState(): void {
		$fold = $this->fold();
		$fold->fold(path: 'authorization.read.0', state: DivergenceComparator::LOCAL);

		$this->assertSame(['authorization.read.0' => 'l-auth'], $fold->mergedParts);
		$this->assertSame(['authorization.read.0'], $fold->preserved);
	}//end testAuthorizationPathWithoutABlockFoldsAsItsOwnState()

	public function testUndecidedUpstreamBlockMemberIsTakenAndApplied(): void {
		$fold = $this->fold(blocks: ['authorization' => ['state' => DivergenceComparator::UPSTREAM, 'decided' => false]]);
		// The member's own state is ignored: the block decides.
		$fold->fold(path: 'authorization.read.0', state: DivergenceComparator::LOCAL);

		$this->assertSame(['authorization.read.0' => 'i-auth'], $fold->mergedParts);
		$this->assertSame(['authorization.read.0' => 'i-auth'], $fold->nextBaseline);
		$this->assertSame(['authorization.read.0'], $fold->applied);
	}//end testUndecidedUpstreamBlockMemberIsTakenAndApplied()

	public function testDecidedUpstreamBlockMemberIsTakenButNotListedAsApplied(): void {
		$fold = $this->fold(blocks: ['authorization' => ['state' => DivergenceComparator::UPSTREAM, 'decided' => true]]);
		$fold->fold(path: 'authorization.read.0', state: DivergenceComparator::BOTH);

		$this->assertSame(['authorization.read.0' => 'i-auth'], $fold->mergedParts);
		$this->assertSame([], $fold->applied);
	}//end testDecidedUpstreamBlockMemberIsTakenButNotListedAsApplied()

	public function testLocalBlockMemberIsPreserved(): void {
		$fold = $this->fold(blocks: ['authorization' => ['state' => DivergenceComparator::LOCAL, 'decided' => false]]);
		$fold->fold(path: 'authorization.read.0', state: DivergenceComparator::UPSTREAM);

		$this->assertSame(['authorization.read.0' => 'l-auth'], $fold->mergedParts);
		$this->assertSame(['authorization.read.0' => 'b-auth'], $fold->nextBaseline);
		$this->assertSame(['authorization.read.0'], $fold->preserved);
		$this->assertSame([], $fold->applied);
	}//end testLocalBlockMemberIsPreserved()

	public function testConflictingBlockStandsWholeWithoutAConflictRowPerMember(): void {
		$fold = $this->fold(blocks: ['authorization' => ['state' => DivergenceComparator::BOTH, 'decided' => false]]);
		$fold->fold(path: 'authorization.read.0', state: DivergenceComparator::BOTH);

		$this->assertSame(['authorization.read.0' => 'l-auth'], $fold->mergedParts);
		$this->assertSame(['authorization.read.0' => 'b-auth'], $fold->nextBaseline);
		$this->assertSame([], $fold->conflicts);
		$this->assertSame([], $fold->applied);
	}//end testConflictingBlockStandsWholeWithoutAConflictRowPerMember()

	public function testPropertyAuthorizationBlockIsFoundByItsRoot(): void {
		$fold = new DescriptorFold(
			sources: [
				'baseline' => [],
				'incoming' => ['properties.secret.authorization.read.0' => 'admin'],
				'live' => ['properties.secret.authorization.read.0' => 'everyone'],
			],
			decisions: [],
			blocks: ['properties.secret.authorization' => ['state' => DivergenceComparator::UPSTREAM, 'decided' => false]],
			authorization: new AuthorizationBlocks(parts: new DescriptorParts())
		);
		$fold->fold(path: 'properties.secret.authorization.read.0', state: DivergenceComparator::LOCAL);

		$this->assertSame(['properties.secret.authorization.read.0' => 'admin'], $fold->mergedParts);
	}//end testPropertyAuthorizationBlockIsFoundByItsRoot()
}//end class
