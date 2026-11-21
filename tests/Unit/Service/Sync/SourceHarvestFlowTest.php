<?php

/**
 * SourceHarvestFlow: one flow per scheduled source, keyed by its uuid.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Flow\FlowPublishOptions;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidatorInterface;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use OCA\OpenRegister\Service\Sync\SourceHarvestFlow;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sync\SourceHarvestFlow
 * @uses   \OCA\OpenRegister\Db\Flow
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Service\Flow\FlowPublishOptions
 */
class SourceHarvestFlowTest extends TestCase {

	/** @var FlowMapperInterface&MockObject */
	private FlowMapperInterface $mapper;

	/** @var FlowVersionServiceInterface&MockObject */
	private FlowVersionServiceInterface $versions;

	/** @var FlowServiceInterface&MockObject */
	private FlowServiceInterface $flows;

	/** @var FlowTriggerValidatorInterface&MockObject */
	private FlowTriggerValidatorInterface $validator;

	private SourceHarvestFlow $writer;

	protected function setUp(): void {
		$this->mapper    = $this->createMock(FlowMapperInterface::class);
		$this->versions  = $this->createMock(FlowVersionServiceInterface::class);
		$this->flows     = $this->createMock(FlowServiceInterface::class);
		$this->validator = $this->createMock(FlowTriggerValidatorInterface::class);
		$this->writer    = new SourceHarvestFlow(mapper: $this->mapper, versions: $this->versions, flows: $this->flows, validator: $this->validator);
	}

	private function source(?string $schedule='15 * * * *', ?string $runAs='harvester'): Source {
		$source = new Source();
		$source->setUuid('src-1');
		$source->setTitle('Catalogue');
		$source->setSchedule($schedule);
		$source->setRunAs($runAs);
		$source->setApplication('opencatalogi');
		$source->setOrganisation('org-1');
		$source->setSyncEnabled(true);
		return $source;
	}

	public function testTheGraphIsScheduleAndManualIntoTheHarvestIntoEnd(): void {
		$nodes = SourceHarvestFlow::nodes($this->source());

		$this->assertSame(['schedule', 'manual', 'harvest', 'end'], array_column($nodes, 'id'));
		$this->assertSame(['cron' => '15 * * * *', 'runAs' => 'harvester'], $nodes[0]['config']);
		$this->assertSame('openregister.trigger-manual', $nodes[1]['type']);
		$this->assertSame(['openregister.harvest-source', ['sourceId' => 'src-1']], [$nodes[2]['type'], $nodes[2]['config']]);
		$this->assertSame(
			[['schedule', 'harvest'], ['manual', 'harvest'], ['harvest', 'end']],
			array_map(static fn (array $e): array => [$e['from'], $e['to']], SourceHarvestFlow::edges())
		);
	}

	public function testAScheduledSourceGetsAFlowKeyedByItsUuidAndPublished(): void {
		$this->mapper->method('findByUuid')->with('src-1')->willThrowException(new DoesNotExistException('none'));
		$this->validator->expects($this->once())->method('validate');

		$inserted = null;
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Flow $flow) use (&$inserted): Flow {
				$inserted = $flow;
				return $flow;
			}
		);
		$this->versions->expects($this->once())->method('publish')
			->with($this->isInstanceOf(Flow::class), $this->callback(static fn (FlowPublishOptions $o): bool => $o->publishedBy === 'admin'));

		$this->assertSame('src-1', $this->writer->sync($this->source(), 'admin'));
		$this->assertSame('src-1', $inserted->getUuid());
		$this->assertSame('opencatalogi', $inserted->getApp());
		$this->assertSame(Flow::TRIGGER_SCHEDULE, $inserted->getTrigger());
		$this->assertSame('15 * * * *', $inserted->getCron());
		$this->assertSame('admin', $inserted->getOwner());
		$this->assertSame('org-1', $inserted->getOrganisation());
		$this->assertTrue($inserted->getEnabled());
	}

	public function testASecondSaveWithTheSameGraphUpdatesInPlaceAndDisables(): void {
		$source = $this->source();
		$stored = new Flow();
		$stored->setUuid('src-1');
		$stored->setOwner('first-admin');
		$stored->setNodes(SourceHarvestFlow::nodes($source));
		$stored->setEdges(SourceHarvestFlow::edges());
		$stored->setCron('15 * * * *');
		$this->mapper->method('findByUuid')->willReturn($stored);
		$this->mapper->expects($this->never())->method('insert');
		$this->versions->expects($this->never())->method('publish');
		$this->mapper->expects($this->once())->method('update')->with($stored)->willReturnArgument(0);

		$source->setSyncEnabled(false);
		$this->assertSame('src-1', $this->writer->sync($source, 'other-admin'));
		$this->assertFalse($stored->getEnabled());
		$this->assertSame('first-admin', $stored->getOwner());
	}

	public function testAChangedScheduleOnAPublishedFlowDraftsThenRepublishes(): void {
		$stored = new Flow();
		$stored->setUuid('src-1');
		$stored->setOwner('admin');
		$stored->setNodes(SourceHarvestFlow::nodes($this->source()));
		$stored->setEdges(SourceHarvestFlow::edges());
		$stored->setCron('15 * * * *');
		$stored->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
		$this->mapper->method('findByUuid')->willReturn($stored);

		$order = [];
		$this->versions->expects($this->once())->method('createDraft')->with($stored)
			->willReturnCallback(static function () use (&$order): FlowVersion {
				$order[] = 'draft';
				return new FlowVersion();
			});
		$this->mapper->expects($this->once())->method('update')
			->willReturnCallback(static function (Flow $flow) use (&$order): Flow {
				$order[] = 'update';
				return $flow;
			});
		$this->versions->expects($this->once())->method('publish')
			->willReturnCallback(static function () use (&$order): FlowVersion {
				$order[] = 'publish';
				return new FlowVersion();
			});

		$this->writer->sync($this->source(schedule: '45 2 * * *'), 'admin');

		$this->assertSame(['draft', 'update', 'publish'], $order);
		$this->assertSame('45 2 * * *', $stored->getCron());
	}

	public function testRemovingTheScheduleDeletesTheFlow(): void {
		$stored = new Flow();
		$this->mapper->method('findByUuid')->willReturn($stored);
		$this->flows->expects($this->once())->method('deleteStored')->with($stored);

		$this->assertNull($this->writer->sync($this->source(schedule: null), 'admin'));
	}

	public function testASourceWithoutRunAsGetsNoFlow(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->expects($this->never())->method('insert');
		$this->flows->expects($this->never())->method('deleteStored');

		$this->assertNull($this->writer->sync($this->source(runAs: null), 'admin'));
	}

	public function testRemoveDeletesAStoredFlowOnly(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('none'));
		$this->flows->expects($this->never())->method('deleteStored');

		$this->writer->remove($this->source());
		$this->addToAssertionCount(1);
	}
}
