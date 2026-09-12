<?php

/**
 * Numbering history the repair did not witness.
 *
 * 🔴 THE STEP CANNOT DERIVE, AND MUST NOT PRETEND TO. The graphs it would have
 * to compare are the ones it is being run to describe, and older definition
 * rows may have been pruned. So it counts minors in ordinal order and stamps
 * `backfill` beside every value it writes. These tests assert the MARKER as
 * hard as they assert the numbers: a back-filled version that cannot be told
 * apart from a derived one is a guess wearing evidence's clothes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Repair\BackfillFlowSemanticVersions;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see BackfillFlowSemanticVersions}.
 *
 * @covers \OCA\OpenRegister\Repair\BackfillFlowSemanticVersions
 * @uses \OCA\OpenRegister\Service\Flow\FlowSemanticVersion
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\FlowVersion
 */
final class BackfillFlowSemanticVersionsTest extends TestCase {

	/**
	 * The version rows the fixture instance holds, by flow uuid.
	 *
	 * @var array<string, array<int, FlowVersion>>
	 */
	private array $rows = [];

	/**
	 * The flows the fixture instance holds.
	 *
	 * @var array<int, Flow>
	 */
	private array $flows = [];

	/**
	 * Rows handed to `update()`, in the order they were written.
	 *
	 * @var array<int, FlowVersion>
	 */
	private array $updated = [];

	/**
	 * Flows handed to the flow mapper's `update()`.
	 *
	 * @var array<int, Flow>
	 */
	private array $flowUpdates = [];

	/**
	 * A version row.
	 *
	 * @param string      $flowUuid The flow it belongs to.
	 * @param integer     $ordinal  Its ordinal version number.
	 * @param string      $status   Its lifecycle status.
	 * @param string|null $semver   A semantic version it already carries.
	 *
	 * @return FlowVersion The row.
	 */
	private function version(string $flowUuid, int $ordinal, string $status, ?string $semver = null): FlowVersion {
		$row = new FlowVersion();
		$row->setFlowUuid($flowUuid);
		$row->setVersion($ordinal);
		$row->setStatus($status);
		$row->setSemver($semver);

		return $row;
	}//end version()

	/**
	 * A flow.
	 *
	 * @param string      $uuid   Its uuid.
	 * @param string|null $semver A semantic version it already carries.
	 *
	 * @return Flow The flow.
	 */
	private function flow(string $uuid, ?string $semver = null): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setSemver($semver);

		return $flow;
	}//end flow()

	/**
	 * The step, wired to the fixture.
	 *
	 * 🔑 `findAllForFlow()` ANSWERS NEWEST FIRST, like the real mapper, because
	 * the UI reads it that way. The step has to sort; a double that handed the
	 * rows over oldest-first would let an unsorted implementation pass and the
	 * test would be asserting the double.
	 *
	 * @return BackfillFlowSemanticVersions The step.
	 */
	private function step(): BackfillFlowSemanticVersions {
		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findAllFlows')->willReturnCallback(
			function (
				?string $app = null,
				?string $applicationSlug = null,
				?string $organisation = null,
				?bool $enabled = null,
				int $limit = 100,
				int $offset = 0,
			): array {
				return array_slice($this->flows, $offset, $limit);
			}
		);
		$flows->method('update')->willReturnCallback(
			function (Flow $flow): Flow {
				$this->flowUpdates[] = $flow;

				return $flow;
			}
		);

		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('findAllForFlow')->willReturnCallback(
			function (string $flowUuid): array {
				return array_reverse(($this->rows[$flowUuid] ?? []));
			}
		);
		$versions->method('update')->willReturnCallback(
			function (FlowVersion $row): FlowVersion {
				$this->updated[] = $row;

				return $row;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($flows, $versions) {
				return match ($id) {
					FlowVersionMapper::class => $versions,
					FlowSemanticVersion::class => new FlowSemanticVersion(),
					default => $flows,
				};
			}
		);

		return new BackfillFlowSemanticVersions($container, $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * Published versions are numbered 1.0.0, 1.1.0, 1.2.0 in ordinal order.
	 *
	 * @return void
	 */
	public function testPublishedVersionsAreNumberedInOrdinalOrder(): void {
		$this->flows = [$this->flow('flow-a')];
		$this->rows = [
			'flow-a' => [
				$this->version('flow-a', 1, FlowVersion::STATUS_DEPRECATED),
				$this->version('flow-a', 2, FlowVersion::STATUS_DEPRECATED),
				$this->version('flow-a', 3, FlowVersion::STATUS_PUBLISHED),
			],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame(
			['1.0.0', '1.1.0', '1.2.0'],
			array_map(static fn (FlowVersion $r): ?string => $r->getSemver(), $this->updated)
		);

		foreach ($this->updated as $row) {
			$this->assertSame(
				FlowSemanticVersion::SOURCE_BACKFILL,
				$row->getSemverSource(),
				'every back-filled value must say so, or it reads as derived'
			);
		}
	}//end testPublishedVersionsAreNumberedInOrdinalOrder()

	/**
	 * 🔴 A HISTORY WITH A GAP IS STILL NUMBERED CONSECUTIVELY.
	 *
	 * Ordinals 1, 2 and 5 — versions 3 and 4 were pruned, or were drafts
	 * abandoned without publishing. The semantic sequence counts the versions
	 * that EXIST, so the flow gets 1.0.0, 1.1.0, 1.2.0 rather than a 1.4.0 with
	 * two numbers nothing ever carried.
	 *
	 * Numbering by the ordinal itself would invent versions: a reader seeing
	 * 1.4.0 would reasonably look for the 1.2.0 that was never published.
	 *
	 * @return void
	 */
	public function testAHistoryWithAGapIsNumberedByWhatSurvives(): void {
		$this->flows = [$this->flow('flow-gap')];
		$this->rows = [
			'flow-gap' => [
				$this->version('flow-gap', 1, FlowVersion::STATUS_DEPRECATED),
				$this->version('flow-gap', 2, FlowVersion::STATUS_DEPRECATED),
				$this->version('flow-gap', 5, FlowVersion::STATUS_PUBLISHED),
			],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame(
			['1.0.0', '1.1.0', '1.2.0'],
			array_map(static fn (FlowVersion $r): ?string => $r->getSemver(), $this->updated),
			'the gap must not become a version nobody ever published'
		);
	}//end testAHistoryWithAGapIsNumberedByWhatSurvives()

	/**
	 * Drafts are skipped, and an existing value is never overwritten.
	 *
	 * A derived version is a stronger fact than anything this step produces.
	 *
	 * @return void
	 */
	public function testDraftsAreSkippedAndDerivedValuesAreLeftAlone(): void {
		$this->flows = [$this->flow('flow-mixed')];
		$this->rows = [
			'flow-mixed' => [
				$this->version('flow-mixed', 1, FlowVersion::STATUS_DEPRECATED, '3.2.0'),
				$this->version('flow-mixed', 2, FlowVersion::STATUS_PUBLISHED),
				$this->version('flow-mixed', 3, FlowVersion::STATUS_DRAFT),
			],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->updated, 'only the one unstamped published row is written');
		$this->assertSame(2, $this->updated[0]->getVersion());
		// Position 1 among the published rows, because the row before it
		// counts even though it was not rewritten.
		$this->assertSame('1.1.0', $this->updated[0]->getSemver());
	}//end testDraftsAreSkippedAndDerivedValuesAreLeftAlone()

	/**
	 * 🔴 THE LIVE VERSION'S NUMBER REACHES THE FLOW ROW.
	 *
	 * `Flow` mirrors `semver` so a list of flows shows it without a join. A
	 * repair that stamped only the version rows would leave every historic flow
	 * showing its ORDINAL in the pill for ever: the rows right, the screen
	 * wrong.
	 *
	 * @return void
	 */
	public function testTheLiveVersionsNumberIsMirroredOntoTheFlow(): void {
		$this->flows = [$this->flow('flow-mirror')];
		$this->rows = [
			'flow-mirror' => [
				$this->version('flow-mirror', 1, FlowVersion::STATUS_DEPRECATED),
				$this->version('flow-mirror', 2, FlowVersion::STATUS_PUBLISHED),
			],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->flowUpdates);
		$this->assertSame('1.1.0', $this->flowUpdates[0]->getSemver());
		$this->assertSame(FlowSemanticVersion::SOURCE_BACKFILL, $this->flowUpdates[0]->getSemverSource());
	}//end testTheLiveVersionsNumberIsMirroredOntoTheFlow()

	/**
	 * A flow that already carries a semantic version is not rewritten.
	 *
	 * @return void
	 */
	public function testAFlowThatAlreadyHasASemanticVersionIsLeftAlone(): void {
		$this->flows = [$this->flow('flow-known', '4.1.0')];
		$this->rows = [
			'flow-known' => [$this->version('flow-known', 1, FlowVersion::STATUS_PUBLISHED)],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->flowUpdates, 'a derived number must not be replaced by a guess');
	}//end testAFlowThatAlreadyHasASemanticVersionIsLeftAlone()

	/**
	 * 🔴 ONE UNREADABLE FLOW MUST NOT FAIL THE UPGRADE.
	 *
	 * A flow whose history cannot be read is already in that state, and turning
	 * a reporting gap into a failed `occ upgrade` makes it everybody's problem.
	 * The other flows are still stamped.
	 *
	 * @return void
	 */
	public function testOneUnreadableFlowDoesNotStopTheRestOrFailTheUpgrade(): void {
		$this->flows = [$this->flow('flow-bad'), $this->flow('flow-good')];
		$this->rows = ['flow-good' => [$this->version('flow-good', 1, FlowVersion::STATUS_PUBLISHED)]];

		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findAllFlows')->willReturnCallback(
			function (
				?string $app = null,
				?string $applicationSlug = null,
				?string $organisation = null,
				?bool $enabled = null,
				int $limit = 100,
				int $offset = 0,
			): array {
				return array_slice($this->flows, $offset, $limit);
			}
		);

		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('findAllForFlow')->willReturnCallback(
			function (string $flowUuid): array {
				if ($flowUuid === 'flow-bad') {
					throw new RuntimeException('the version rows are unreadable');
				}

				return array_reverse(($this->rows[$flowUuid] ?? []));
			}
		);
		$versions->method('update')->willReturnCallback(
			function (FlowVersion $row): FlowVersion {
				$this->updated[] = $row;

				return $row;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($flows, $versions) {
				return match ($id) {
					FlowVersionMapper::class => $versions,
					FlowSemanticVersion::class => new FlowSemanticVersion(),
					default => $flows,
				};
			}
		);

		$step = new BackfillFlowSemanticVersions($container, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->updated, 'the readable flow is still stamped');
		$this->assertSame('flow-good', (string)$this->updated[0]->getFlowUuid());
	}//end testOneUnreadableFlowDoesNotStopTheRestOrFailTheUpgrade()

	/**
	 * Missing tables are a skip, not a failure.
	 *
	 * The step runs during install, when the flow tables may not exist yet.
	 *
	 * @return void
	 */
	public function testMissingTablesAreASkipRatherThanAFailure(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such table'));

		$step = new BackfillFlowSemanticVersions($container, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->updated);
	}//end testMissingTablesAreASkipRatherThanAFailure()

	/**
	 * The step names itself for `occ upgrade`.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$this->assertNotSame('', $this->step()->getName());
	}//end testTheStepNamesItself()
}//end class
