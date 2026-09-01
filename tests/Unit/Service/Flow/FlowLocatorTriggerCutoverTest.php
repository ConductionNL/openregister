<?php

/**
 * The cutover's one promise: no flow changes which events it fires on.
 *
 * `FlowLocator` now matches object events against the node-derived trigger
 * INDEX, falling back to the old trigger columns for flows the index does not
 * represent. That fallback is the whole safety property, and it has three
 * distinct failure modes this file pins:
 *
 *   - an unconverted flow that STOPS firing (the fallback did not apply)
 *   - a converted flow that fires from its stale columns anyway (the fallback
 *     applied when it should not have)
 *   - a flow started TWICE because both sources matched it
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowLocator
 * @uses \OCA\OpenRegister\Db\FlowVersion
 */
class FlowLocatorTriggerCutoverTest extends TestCase {

	private FlowMapper|MockObject $mapper;

	private FlowTriggerMapper|MockObject $triggerMapper;

	private LoggerInterface|MockObject $logger;

	private FlowLocator $locator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(FlowMapper::class);
		$this->triggerMapper = $this->createMock(FlowTriggerMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->locator = new FlowLocator(
			mapper: $this->mapper,
			triggerMapper: $this->triggerMapper,
			objectService: $this->createMock(ObjectService::class),
			logger: $this->logger,
			versions: $this->publishedVersions()
		);

	}//end setUp()

	/**
	 * A version store where every flow has a published version — the shape a
	 * backfilled instance has, so these tests stay about the cutover.
	 *
	 * @return FlowVersionMapper The mapped double.
	 */
	private function publishedVersions(): FlowVersionMapper {
		$published = new FlowVersion();
		$published->setStatus(FlowVersion::STATUS_PUBLISHED);
		$published->setVersion(1);

		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('findPublished')->willReturn($published);

		return $versions;
	}//end publishedVersions()

	/**
	 * A dispatchable flow with the given uuid.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow The flow.
	 */
	private function flow(string $uuid): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setEnabled(true);
		// canDispatch() needs an owner — a trigger has no acting user.
		$flow->setOwner('alice');

		return $flow;
	}//end flow()

	/**
	 * 🔴 A DRAFT MATCHES NOTHING, on the fallback path too. An enabled flow
	 * that was never published has zero index rows, so it used to slip through
	 * the column fallback as "unconverted" — and the queue's refusal of it
	 * then aborted the whole fan-out for every healthy flow on the event. The
	 * locator must offer only flows a published version can back; the sibling
	 * with one still fires.
	 *
	 * @return void
	 */
	public function testAnUnpublishedFlowIsFilteredFromTheColumnFallback(): void {
		$this->triggerMapper->method('flowUuidsFor')->willReturn([]);
		$this->triggerMapper->method('representedFlowUuids')->willReturn([]);
		$this->mapper->method('findByTrigger')->willReturn([$this->flow('never-published'), $this->flow('published-1')]);

		$published = new FlowVersion();
		$published->setStatus(FlowVersion::STATUS_PUBLISHED);
		$published->setVersion(1);

		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('findPublished')->willReturnCallback(
			static function (string $flowUuid) use ($published): ?FlowVersion {
				if ($flowUuid === 'never-published') {
					return null;
				}

				return $published;
			}
		);

		$locator = new FlowLocator(
			mapper: $this->mapper,
			triggerMapper: $this->triggerMapper,
			objectService: $this->createMock(ObjectService::class),
			logger: $this->logger,
			versions: $versions
		);

		$this->assertSame(
			['published-1'],
			$locator->flowsForTrigger('object.created', 'dossiq', 'case'),
			'an enabled-but-unpublished flow must not be offered to the queue; its published sibling must be'
		);
	}//end testAnUnpublishedFlowIsFilteredFromTheColumnFallback()

	/**
	 * An UNCONVERTED flow keeps firing through its columns.
	 *
	 * This is the case every flow in a real instance is in the moment the
	 * cutover ships, and the one that would break loudest.
	 *
	 * @return void
	 */
	public function testAnUnconvertedFlowStillFiresThroughItsColumns(): void {
		$this->triggerMapper->method('flowUuidsFor')->willReturn([]);
		$this->triggerMapper->method('representedFlowUuids')->willReturn([]);
		$this->mapper->method('findByTrigger')->willReturn([$this->flow('legacy-1')]);

		$this->assertSame(
			['legacy-1'],
			$this->locator->flowsForTrigger('object.created', 'hydra', 'finding'),
			'an unconverted flow stopped firing — the column fallback did not apply'
		);

	}//end testAnUnconvertedFlowStillFiresThroughItsColumns()

	/**
	 * A CONVERTED flow fires from its nodes.
	 *
	 * @return void
	 */
	public function testAConvertedFlowFiresFromTheIndex(): void {
		$this->triggerMapper->method('flowUuidsFor')->willReturn(['converted-1']);
		$this->triggerMapper->method('representedFlowUuids')->willReturn(['converted-1']);
		$this->mapper->method('findByTrigger')->willReturn([]);
		$this->mapper->method('findByUuid')->willReturn($this->flow('converted-1'));

		$this->assertSame(
			['converted-1'],
			$this->locator->flowsForTrigger('object.created', 'hydra', 'finding')
		);

	}//end testAConvertedFlowFiresFromTheIndex()

	/**
	 * THE CASE THE FALLBACK MUST NOT COVER: a converted flow whose stale
	 * columns still name this event does NOT fire.
	 *
	 * Deleting a trigger node has to actually unsubscribe the flow. If the
	 * columns were consulted for a converted flow, the removed node would keep
	 * firing forever through a column nobody edits.
	 *
	 * @return void
	 */
	public function testAConvertedFlowDoesNotFireFromItsStaleColumns(): void {
		// The index knows this flow, but NOT for this event.
		$this->triggerMapper->method('flowUuidsFor')->willReturn([]);
		$this->triggerMapper->method('representedFlowUuids')->willReturn(['converted-1']);

		// Its old column still matches.
		$this->mapper->method('findByTrigger')->willReturn([$this->flow('converted-1')]);

		$this->assertSame(
			[],
			$this->locator->flowsForTrigger('object.created', 'hydra', 'finding'),
			'a converted flow fired from a trigger column its nodes no longer declare'
		);

	}//end testAConvertedFlowDoesNotFireFromItsStaleColumns()

	/**
	 * A flow matched by BOTH sources is started once, not twice.
	 *
	 * @return void
	 */
	public function testAFlowMatchedByBothSourcesIsReturnedOnce(): void {
		$this->triggerMapper->method('flowUuidsFor')->willReturn(['both-1']);
		$this->triggerMapper->method('representedFlowUuids')->willReturn([]);
		$this->mapper->method('findByTrigger')->willReturn([$this->flow('both-1')]);
		$this->mapper->method('findByUuid')->willReturn($this->flow('both-1'));

		$this->assertSame(
			['both-1'],
			$this->locator->flowsForTrigger('object.created', 'hydra', 'finding'),
			'one flow was started twice by a single event'
		);

	}//end testAFlowMatchedByBothSourcesIsReturnedOnce()

	/**
	 * An UNREADABLE index degrades to the columns, not to silence.
	 *
	 * During the upgrade that creates the table the index does not exist yet.
	 * Returning "no flow was interested" there would stop the entire engine,
	 * and would look exactly like a quiet afternoon.
	 *
	 * @return void
	 */
	public function testAnUnreadableIndexFallsBackToTheColumnsForEveryFlow(): void {
		$this->triggerMapper->method('flowUuidsFor')
			->willThrowException(new RuntimeException('no such table'));
		$this->mapper->method('findByTrigger')->willReturn([$this->flow('legacy-1')]);

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame(
			['legacy-1'],
			$this->locator->flowsForTrigger('object.created', 'hydra', 'finding'),
			'an unreadable index silenced the engine instead of falling back'
		);

	}//end testAnUnreadableIndexFallsBackToTheColumnsForEveryFlow()

	/**
	 * An index row naming a flow that no longer exists is reported, not
	 * silently skipped — it means a delete did not reach the index.
	 *
	 * @return void
	 */
	public function testAStaleIndexRowIsReported(): void {
		$this->triggerMapper->method('flowUuidsFor')->willReturn(['ghost-1']);
		$this->triggerMapper->method('representedFlowUuids')->willReturn(['ghost-1']);
		$this->mapper->method('findByTrigger')->willReturn([]);
		$this->mapper->method('findByUuid')->willThrowException(new RuntimeException('gone'));

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame([], $this->locator->flowsForTrigger('object.created', 'hydra', 'finding'));

	}//end testAStaleIndexRowIsReported()

	/**
	 * An ownerless flow is still refused OUT LOUD, whichever source matched it.
	 *
	 * @return void
	 */
	public function testAnOwnerlessFlowFromTheIndexIsRefusedNotDispatched(): void {
		$ownerless = new Flow();
		$ownerless->setUuid('ownerless-1');
		$ownerless->setEnabled(true);

		$this->triggerMapper->method('flowUuidsFor')->willReturn(['ownerless-1']);
		$this->triggerMapper->method('representedFlowUuids')->willReturn(['ownerless-1']);
		$this->mapper->method('findByTrigger')->willReturn([]);
		$this->mapper->method('findByUuid')->willReturn($ownerless);

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame([], $this->locator->flowsForTrigger('object.created', 'hydra', 'finding'));

	}//end testAnOwnerlessFlowFromTheIndexIsRefusedNotDispatched()

}//end class
