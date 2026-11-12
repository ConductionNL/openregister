<?php

/**
 * MigrateRegisterFlowsToTable accepts every row shape findAll() can hand it and counts what it refuses.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Repair\MigrateRegisterFlowsToTable;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The row-shape and lookup-failure branches the existing test leaves.
 */
class MigrateRegisterFlowsToTableShapesTest extends TestCase {

	private FlowMapperInterface&MockObject $flows;
	private ObjectOperationsInterface&MockObject $objects;
	private LoggerInterface&MockObject $logger;

	/** @var list<string> */
	private array $info = [];

	/** @var list<Flow> */
	private array $inserted = [];

	protected function setUp(): void {
		$this->flows = $this->createMock(FlowMapperInterface::class);
		$this->flows->method('insert')->willReturnCallback(function (Flow $flow): Flow {
			$this->inserted[] = $flow;
			return $flow;
		});
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function runStep(): void {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $m): void {
			$this->info[] = $m;
		});
		(new MigrateRegisterFlowsToTable($this->flows, $this->objects, $this->logger))->run($output);
	}

	private function summary(int $moved, int $already, int $notFlows, int $failed): string {
		return sprintf(
			'Register-authored flows: %d migrated, %d already in the table, %d not a flow definition, %d failed (register rows left in place).',
			$moved,
			$already,
			$notFlows,
			$failed
		);
	}

	public function testTheStepNameIsWhatOccPrints(): void {
		$this->assertSame(
			'Migrate register-authored flows into the flow table (one store per flow)',
			(new MigrateRegisterFlowsToTable($this->flows, $this->objects, $this->logger))->getName()
		);
	}

	public function testArrayRowsAreReadFromSelfOrTopLevelAndAnEdgesOnlyGraphIsAFlow(): void {
		$this->objects->method('findAll')->willReturn(
			[
				'results' => [
					['@self' => ['id' => 'u-self', 'owner' => 'alice', 'organisation' => 'org-a'], 'name' => 'A', 'edges' => [['from' => 'a']]],
					['@self' => 'not-an-array', 'id' => 'u-top', 'owner' => 'bob', 'organisation' => 'org-b', 'trigger' => 'object.created'],
				],
			]
		);
		$this->flows->method('findByUuid')->willThrowException(new DoesNotExistException('new'));

		$this->runStep();

		$this->assertCount(2, $this->inserted);
		$this->assertSame('u-self', $this->inserted[0]->getUuid());
		$this->assertSame('alice', $this->inserted[0]->getOwner());
		$this->assertSame('org-a', $this->inserted[0]->getOrganisation());
		$this->assertSame('A', $this->inserted[0]->getName());
		$this->assertSame('u-top', $this->inserted[1]->getUuid());
		$this->assertSame('bob', $this->inserted[1]->getOwner());
		$this->assertSame('org-b', $this->inserted[1]->getOrganisation());
		$this->assertSame([$this->summary(2, 0, 0, 0)], $this->info);
	}

	public function testARowThatIsNeitherEntityNorArrayAndARowWithoutUuidAreFailures(): void {
		$this->objects->method('findAll')->willReturn(['results' => ['a string', ['nodes' => [['id' => 'n']]]]]);
		$this->flows->expects($this->never())->method('findByUuid');

		$this->runStep();

		$this->assertSame([], $this->inserted);
		$this->assertSame([$this->summary(0, 0, 0, 2)], $this->info);
	}

	public function testAResultWithoutAListMigratesNothing(): void {
		$this->objects->method('findAll')->willReturn(['results' => 'nope']);
		$this->flows->expects($this->never())->method('findByUuid');

		$this->runStep();

		$this->assertSame([$this->summary(0, 0, 0, 0)], $this->info);
	}

	public function testAFailingLookupIsCountedAsAFailureAndNothingIsInserted(): void {
		$this->objects->method('findAll')->willReturn(['results' => [['id' => 'u-1', 'trigger' => 'x']]]);
		$this->flows->method('findByUuid')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('[MigrateRegisterFlowsToTable] lookup failed for u-1: db gone');

		$this->runStep();

		$this->assertSame([], $this->inserted);
		$this->assertSame([$this->summary(0, 0, 0, 1)], $this->info);
	}

	public function testABlankTriggerIsNotAFlowDefinition(): void {
		$this->objects->method('findAll')->willReturn(['results' => [['id' => 'u-1', 'trigger' => '   ']]]);
		$this->flows->expects($this->never())->method('findByUuid');

		$this->runStep();

		$this->assertSame([$this->summary(0, 0, 1, 0)], $this->info);
	}
}
