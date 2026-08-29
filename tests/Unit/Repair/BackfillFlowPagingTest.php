<?php

/**
 * The two flow repair steps must see EVERY flow, not the first page of them.
 *
 * 🔴 `FlowMapper::findAllFlows()` DEFAULTS TO `limit: 100`. Both steps called it
 * bare, so each processed one page and printed a cheerful summary. Measured on a
 * dev instance immediately after the versioning upgrade: 219 flows, 100
 * versioned, 119 left with no published version — and a flow with no published
 * version backs no run. The step that exists to stop versioning being a fleet
 * outage was producing one, on 54% of the flows.
 *
 * On the trigger index the same bug is worse, because its symptom is pure
 * silence: a flow past the first page is never subscribed and simply never
 * fires. Nothing raises, nothing logs, and the summary line still reads as
 * success — it counts the flows it LOOKED at, not the flows that exist.
 *
 * 🔑 THE FIXTURE IS DELIBERATELY 1,003 FLOWS, and the mapper double is a real
 * pager rather than a canned list. A double that ignored `limit`/`offset` and
 * always returned everything would make BOTH the paged and the unpaged
 * implementation pass — the test would be asserting the double's behaviour
 * rather than the step's. Honouring the arguments is what makes the truncation
 * reproducible here at all. 1,003 also exercises three pages plus a short final
 * one, so an off-by-one in the exit condition shows up as a wrong count rather
 * than as a hang.
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
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Repair\BackfillFlowTriggerIndex;
use OCA\OpenRegister\Repair\BackfillFlowVersions;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Both flow repair steps page to exhaustion.
 */
final class BackfillFlowPagingTest extends TestCase {

	/**
	 * How many flows the instance under test holds.
	 *
	 * Past two full 500-row pages, so a step that stops after one page — or
	 * after two — is distinguishable from one that reaches the end.
	 *
	 * @var integer
	 */
	private const FLOWS = 1003;

	/**
	 * Every flow uuid the fixture instance holds, in order.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function uuids(): array {
		$uuids = [];
		for ($i = 0; $i < self::FLOWS; $i++) {
			$uuids[] = sprintf('flow-%04d', $i);
		}

		return $uuids;
	}//end uuids()

	/**
	 * A flow mapper that pages for real.
	 *
	 * `findAllFlows()` honours `limit` and `offset` exactly as the real one
	 * does, which is the whole point: a double that ignored them would let an
	 * unpaged caller pass and the test would prove nothing.
	 *
	 * @param array<int, string> $uuids   The uuids the instance holds.
	 * @param array<int, array>  $seenRef Receives one `{limit, offset}` entry per call.
	 *
	 * @return FlowMapper The configured double.
	 */
	private function pagingMapper(array $uuids, array &$seenRef): FlowMapper {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willReturnCallback(
			function (
				?string $app = null,
				?string $applicationSlug = null,
				?string $organisation = null,
				?bool $enabled = null,
				int $limit = 100,
				int $offset = 0,
			) use ($uuids, &$seenRef): array {
				$seenRef[] = ['limit' => $limit, 'offset' => $offset];

				$page = [];
				foreach (array_slice($uuids, $offset, $limit) as $uuid) {
					$flow = new Flow();
					$flow->setUuid($uuid);
					$flow->setNodes([]);
					$flow->setEdges([]);
					$page[] = $flow;
				}

				return $page;
			}
		);

		return $mapper;
	}//end pagingMapper()

	/**
	 * 🔴 THE VERSION BACK-FILL MUST VERSION EVERY FLOW.
	 *
	 * Asserted on the flows actually VERSIONED — the version rows inserted —
	 * rather than on the number of mapper calls, because "it paged" is not the
	 * claim; "no flow was left unrunnable" is. Reverting `everyFlow()` to a bare
	 * `findAllFlows()` turns this red at 100 of 1,003.
	 *
	 * @return void
	 */
	public function testTheVersionBackfillVersionsEveryFlowNotTheFirstPage(): void {
		$calls = [];
		$mapper = $this->pagingMapper($this->uuids(), $calls);

		$versioned = [];
		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('highestVersion')->willReturn(0);
		$versions->method('insert')->willReturnCallback(
			function (FlowVersion $version) use (&$versioned) {
				$versioned[] = (string)$version->getFlowUuid();

				return $version;
			}
		);

		$pin = $this->createMock(FlowDefinitionPin::class);
		$pin->method('pin')->willReturn('deadbeef');

		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('pinUnversionedActive')->willReturn(0);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $versions, $pin, $runs) {
				return match ($id) {
					FlowVersionMapper::class => $versions,
					FlowDefinitionPin::class => $pin,
					FlowRunMapper::class => $runs,
					default => $mapper,
				};
			}
		);

		$step = new BackfillFlowVersions($container, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertCount(
			self::FLOWS,
			$versioned,
			'every flow on the instance must get a published version 1 — a flow the '
				. 'back-fill skipped is a flow that backs no run'
		);
		$this->assertSame(
			$this->uuids(),
			$versioned,
			'the pages must be distinct and in order — an offset that does not '
				. 'advance re-versions page one and never reaches the tail'
		);
	}//end testTheVersionBackfillVersionsEveryFlowNotTheFirstPage()

	/**
	 * The pager asks for pages, and asks for the NEXT one each time.
	 *
	 * Complements the assertion above rather than repeating it: that one proves
	 * the outcome, this one proves the mechanism, so a future implementation
	 * that reached the same total by asking for one enormous page — which
	 * truncates silently the day an instance outgrows it — is still visible.
	 *
	 * @return void
	 */
	public function testTheVersionBackfillAdvancesItsOffset(): void {
		$calls = [];
		$mapper = $this->pagingMapper($this->uuids(), $calls);

		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('highestVersion')->willReturn(1);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $versions) {
				return match ($id) {
					FlowVersionMapper::class => $versions,
					default => $mapper,
				};
			}
		);

		$step = new BackfillFlowVersions($container, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(
			[0, 500, 1000],
			array_column($calls, 'offset'),
			'the back-fill must walk the instance page by page until a short page ends it'
		);
	}//end testTheVersionBackfillAdvancesItsOffset()

	/**
	 * 🔑 IDEMPOTENT BY QUERY. A flow that already carries a version row is left
	 * alone, so `occ maintenance:repair` can be re-run without inventing a
	 * second version 1.
	 *
	 * @return void
	 */
	public function testAnAlreadyVersionedFlowIsNotVersionedAgain(): void {
		$calls = [];
		$mapper = $this->pagingMapper(['flow-0000'], $calls);

		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('highestVersion')->willReturn(3);
		$versions->expects($this->never())->method('insert');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $versions) {
				return match ($id) {
					FlowVersionMapper::class => $versions,
					default => $mapper,
				};
			}
		);

		$step = new BackfillFlowVersions($container, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));
	}//end testAnAlreadyVersionedFlowIsNotVersionedAgain()

	/**
	 * 🔴 THE TRIGGER INDEX MUST BE DERIVED FROM EVERY FLOW.
	 *
	 * The same paging bug, with a silent symptom: a flow past the first page is
	 * never subscribed, so it never fires, and nothing anywhere says so.
	 * Asserted on the flows handed to `rebuild()`, because that set IS the
	 * subscription. Reverting the loop to a bare `findAllFlows()` turns this red
	 * at 100 of 1,003.
	 *
	 * @return void
	 */
	public function testTheTriggerIndexBackfillDerivesFromEveryFlow(): void {
		$calls = [];
		$mapper = $this->pagingMapper($this->uuids(), $calls);

		$rebuilt = null;
		$index = $this->createMock(FlowTriggerIndex::class);
		$index->method('rebuild')->willReturnCallback(
			function (array $flows) use (&$rebuilt): array {
				$rebuilt = $flows;

				return ['indexed' => count($flows), 'rows' => count($flows), 'unconverted' => []];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $index) {
				return match ($id) {
					FlowTriggerIndex::class => $index,
					default => $mapper,
				};
			}
		);

		$step = new BackfillFlowTriggerIndex($container, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertNotNull($rebuilt, 'the index was never rebuilt at all');
		$this->assertCount(
			self::FLOWS,
			$rebuilt,
			'every flow must reach the index — one that does not is never subscribed '
				. 'and never fires, with nothing to say so'
		);
		$this->assertSame(
			$this->uuids(),
			array_map(static fn (Flow $flow): string => (string)$flow->getUuid(), $rebuilt),
			'the pages must be distinct and in order'
		);
	}//end testTheTriggerIndexBackfillDerivesFromEveryFlow()

	/**
	 * An instance with no flows says so and rebuilds nothing.
	 *
	 * The short-circuit matters: `rebuild([])` would report "0 of 0 flows" as a
	 * success line, which reads identically to a back-fill that failed to load
	 * any.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoFlowsRebuildsNothing(): void {
		$calls = [];
		$mapper = $this->pagingMapper([], $calls);

		$index = $this->createMock(FlowTriggerIndex::class);
		$index->expects($this->never())->method('rebuild');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $index) {
				return match ($id) {
					FlowTriggerIndex::class => $index,
					default => $mapper,
				};
			}
		);

		$step = new BackfillFlowTriggerIndex($container, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(
			[['limit' => 500, 'offset' => 0]],
			$calls,
			'an empty instance is one short page, not a loop'
		);
	}//end testAnInstanceWithNoFlowsRebuildsNothing()

	/**
	 * 🔑 ONE FLOW'S FAILURE MUST NOT COST THE OTHERS THEIR VERSIONS.
	 *
	 * An aborted loop here is the difference between one unrunnable flow and an
	 * unrunnable instance — and this step exists precisely because an instance
	 * where nothing has a published version is an outage. So a flow whose
	 * definition cannot be stored is warned about and skipped, and the walk
	 * continues.
	 *
	 * Asserted on the flows that DID get versioned, not on the warning: a
	 * version that reports the failure and then stops is exactly the behaviour
	 * being ruled out.
	 *
	 * @return void
	 */
	public function testOneFlowThatCannotBeStoredDoesNotStopTheRest(): void {
		$calls = [];
		$mapper = $this->pagingMapper(['flow-0000', 'flow-0001', 'flow-0002'], $calls);

		$versioned = [];
		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('highestVersion')->willReturn(0);
		$versions->method('insert')->willReturnCallback(
			function (FlowVersion $version) use (&$versioned) {
				$versioned[] = (string)$version->getFlowUuid();

				return $version;
			}
		);

		// The middle flow cannot be pinned. `backfillOne()` turns a null hash
		// into a RuntimeException, which is what the per-flow catch is for.
		$pin = $this->createMock(FlowDefinitionPin::class);
		$pin->method('pin')->willReturnCallback(
			static fn (array $flow, string $flowId): ?string => $flowId === 'flow-0001' ? null : 'deadbeef'
		);

		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('pinUnversionedActive')->willReturn(0);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $versions, $pin, $runs) {
				return match ($id) {
					FlowVersionMapper::class => $versions,
					FlowDefinitionPin::class => $pin,
					FlowRunMapper::class => $runs,
					default => $mapper,
				};
			}
		);

		$step = new BackfillFlowVersions($container, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(
			['flow-0000', 'flow-0002'],
			$versioned,
			'the flows after the failing one must still be versioned — an aborted '
				. 'walk turns one broken flow into a broken instance'
		);
	}//end testOneFlowThatCannotBeStoredDoesNotStopTheRest()

	/**
	 * 🔑 THE TRIGGER BACK-FILL NEVER THROWS. It runs inside `occ
	 * maintenance:repair` and during upgrade: an exception escaping here aborts
	 * the upgrade, which is strictly worse than an index that needs rebuilding.
	 *
	 * Asserted by calling it — the failure mode is an exception leaving this
	 * method, so a test that completes IS the assertion, and it is made explicit
	 * with `expectNotToPerformAssertions()` being deliberately NOT used: the
	 * warning is asserted instead, so the test cannot pass by never reaching the
	 * rebuild at all.
	 *
	 * @return void
	 */
	public function testAFailingRebuildIsWarnedAboutRatherThanThrown(): void {
		$calls = [];
		$mapper = $this->pagingMapper(['flow-0000'], $calls);

		$index = $this->createMock(FlowTriggerIndex::class);
		$index->method('rebuild')->willThrowException(
			new \RuntimeException('the trigger table is missing')
		);

		$warned = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(
			function (string $message) use (&$warned): void {
				$warned[] = $message;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($mapper, $index) {
				return match ($id) {
					FlowTriggerIndex::class => $index,
					default => $mapper,
				};
			}
		);

		$step = new BackfillFlowTriggerIndex($container, $this->createMock(LoggerInterface::class));
		$step->run($output);

		$this->assertCount(
			1,
			$warned,
			'a failed rebuild must be reported, not swallowed and not thrown — an '
				. 'exception here aborts the upgrade that is carrying the fix'
		);
		$this->assertStringContainsString('the trigger table is missing', $warned[0]);
	}//end testAFailingRebuildIsWarnedAboutRatherThanThrown()

}//end class
