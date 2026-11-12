<?php

/**
 * Behaviour tests for ExampleSetFlows: which flow rows a set may declare, and how
 * a flow is written, updated in place, checked and reverted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Behaviour of the flows an example set declares.
 */
class ExampleSetFlowsBehaviourTest extends TestCase {

	/** @var FlowMapper&MockObject */
	private FlowMapper $mapper;

	/** @var FlowVersionService&MockObject */
	private FlowVersionService $versions;

	/** @var FlowService&MockObject */
	private FlowService $flowService;

	/** @var OrganisationServiceInterface&MockObject */
	private OrganisationServiceInterface $organisations;

	protected function setUp(): void {
		$this->mapper        = $this->createMock(FlowMapper::class);
		$this->versions      = $this->createMock(FlowVersionService::class);
		$this->flowService   = $this->createMock(FlowService::class);
		$this->organisations = $this->createMock(OrganisationServiceInterface::class);
		$this->organisations->method('getOrganisationForNewEntity')->willReturn('org-1');
	}//end setUp()

	private function flows(): ExampleSetFlows {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[FlowMapper::class, $this->mapper],
				[FlowVersionService::class, $this->versions],
				[FlowService::class, $this->flowService],
				[OrganisationServiceInterface::class, $this->organisations],
			]
		);
		return new ExampleSetFlows($container);
	}//end flows()

	private static function valid(array $override=[]): array {
		return array_merge(
			[
				'name' => 'Welkom',
				'nodes' => [['id' => 'start', 'type' => 'trigger'], ['id' => 'mail', 'type' => 'email']],
				'edges' => [['from' => 'start', 'to' => 'mail']],
			],
			$override
		);
	}//end valid()

	private static function version(int $number): FlowVersion {
		$version = new FlowVersion();
		$version->setVersion($number);
		return $version;
	}//end version()

	private static function storedFlow(string $uuid='flow-1'): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setName('Welkom');
		$flow->setNodes(self::valid()['nodes']);
		$flow->setEdges(self::valid()['edges']);
		$flow->setAnnotations([]);
		$flow->setLimits([]);
		$flow->setExecutionMode('async');
		return $flow;
	}//end storedFlow()

	public function testNamesThePartNounAndUpdatesInPlace(): void {
		$flows = $this->flows();

		$this->assertSame('flows', $flows->part());
		$this->assertSame('flow', $flows->noun());
		$this->assertTrue($flows->updatesInPlace());
	}

	public function testAValidFlowHasNoRefusal(): void {
		$this->assertSame('', $this->flows()->refusal(self::valid(['publish' => true, 'enabled' => true, 'applicationSlug' => 'zaken']), []));
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refusals(): array {
		$noName = self::valid();
		unset($noName['name']);
		return [
			'unknown field' => [self::valid(['owner' => 'x']), 'field "owner" is not a flow field a set may write'],
			'no name' => [$noName, 'a flow needs a name'],
			'blank name' => [self::valid(['name' => '  ']), 'a flow needs a name'],
			'no nodes' => [self::valid(['nodes' => []]), 'a flow needs its steps (nodes)'],
			'nodes not a list' => [self::valid(['nodes' => 'start']), 'a flow needs its steps (nodes)'],
			'step without type' => [self::valid(['nodes' => [['id' => 'start']]]), 'every step needs an id and a type'],
			'line to nowhere' => [self::valid(['edges' => [['from' => 'start', 'to' => 'ghost']]]), 'every line runs from one step of the flow to another (from, to)'],
			'line from nowhere' => [self::valid(['edges' => [['to' => 'mail']]]), 'every line runs from one step of the flow to another (from, to)'],
			'publish not boolean' => [self::valid(['publish' => 'yes']), 'publish is true or false'],
			'enabled not boolean' => [self::valid(['enabled' => 1]), 'enabled is true or false'],
			'slug not text' => [self::valid(['applicationSlug' => 5]), 'applicationSlug is text'],
			'on but unpublished' => [self::valid(['enabled' => true, 'publish' => false]), 'a flow that is switched on is published: it runs its published version'],
			'runs not a list' => [self::valid(['runs' => 'x']), 'runs is a list'],
		];
	}//end refusals()

	/**
	 * @dataProvider refusals
	 */
	public function testRefusesWhatAFlowMayNotDeclare(array $data, string $expected): void {
		$this->assertSame($expected, $this->flows()->refusal($data, []));
	}

	public function testAnUnpublishedFlowThatIsOffIsAllowed(): void {
		$this->assertSame('', $this->flows()->refusal(self::valid(['enabled' => false, 'publish' => false]), []));
	}

	public function testWriteInsertsADisabledFlowPublishesItAndFillsItsOwnUuid(): void {
		$inserted = null;
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Flow $flow) use (&$inserted): Flow {
				$inserted = $flow;
				return $flow;
			}
		);
		$this->versions->expects($this->once())->method('publish')->willReturn(self::version(1));
		$this->mapper->expects($this->never())->method('update');

		$result = $this->flows()->write(
			new ExampleSetRow('myapp', 'k', self::valid(['description' => 'Stuurt {{flow:self.uuid}}', 'cron' => '0 * * * *']))
		);

		$this->assertSame('', $result['error']);
		$uuid = $inserted->getUuid();
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
		$this->assertSame(['uuid' => $uuid], $result['entry']);
		$this->assertSame('myapp', $inserted->getApp());
		$this->assertSame('Stuurt '.$uuid, $inserted->getDescription());
		$this->assertSame('0 * * * *', $inserted->getCron());
		$this->assertSame('async', $inserted->getExecutionMode());
		$this->assertFalse($inserted->getEnabled());
		$this->assertNull($inserted->getOwner());
		$this->assertSame('org-1', $inserted->getOrganisation());
		$this->assertSame([], $inserted->getAnnotations());
	}

	public function testWriteOfAnEnabledFlowSwitchesItOnAfterPublishing(): void {
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->versions->expects($this->once())->method('publish')->willReturn(self::version(1));
		$this->mapper->expects($this->once())->method('update')->with($this->callback(static fn (Flow $f): bool => $f->getEnabled() === true))->willReturnArgument(0);

		$this->assertSame('', $this->flows()->write(new ExampleSetRow('app', 'k', self::valid(['enabled' => true])))['error']);
	}

	public function testWriteOfAnUnpublishedFlowDoesNotPublish(): void {
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->versions->expects($this->never())->method('publish');

		$this->assertSame('', $this->flows()->write(new ExampleSetRow('app', 'k', self::valid(['publish' => false, 'executionMode' => 'sync'])))['error']);
	}

	public function testWriteReportsAFailure(): void {
		$this->mapper->method('insert')->willThrowException(new \RuntimeException('duplicate'));

		$this->assertSame(['error' => 'duplicate', 'entry' => null], $this->flows()->write(new ExampleSetRow('app', 'k', self::valid())));
	}

	public function testUpdateOfAGoneFlowWritesItAnew(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$this->versions->method('publish')->willReturn(self::version(1));

		$result = $this->flows()->update(['uuid' => 'flow-1'], new ExampleSetRow('app', 'k', self::valid()));

		$this->assertNotSame('flow-1', $result['entry']['uuid']);
	}

	public function testUpdateWithoutChangesTouchesNothingAndKeepsRunBookkeeping(): void {
		$this->mapper->method('findByUuid')->with('flow-1')->willReturn(self::storedFlow());
		$this->mapper->expects($this->never())->method('update');
		$this->versions->expects($this->never())->method('publishShippedUpdate');

		$result = $this->flows()->update(
			['uuid' => 'flow-1', 'runs' => ['r1'], 'waitsOn' => ['t1'], 'other' => 'dropped'],
			new ExampleSetRow('app', 'k', self::valid())
		);

		$this->assertSame(['error' => '', 'entry' => ['uuid' => 'flow-1', 'runs' => ['r1'], 'waitsOn' => ['t1']]], $result);
	}

	public function testUpdateWithAChangeSavesAndPublishesTheShippedUpdate(): void {
		$flow = self::storedFlow();
		$this->mapper->method('findByUuid')->willReturn($flow);
		$this->mapper->expects($this->once())->method('update')->with($flow);
		$this->versions->expects($this->once())->method('publishShippedUpdate')->with($flow);

		$result = $this->flows()->update(['uuid' => 'flow-1'], new ExampleSetRow('app', 'k', self::valid(['name' => 'Welkom 2'])));

		$this->assertSame('', $result['error']);
		$this->assertSame('Welkom 2', $flow->getName());
	}

	public function testUpdateOfAnUnpublishedChangeSavesWithoutPublishing(): void {
		$this->mapper->method('findByUuid')->willReturn(self::storedFlow());
		$this->mapper->expects($this->once())->method('update');
		$this->versions->expects($this->never())->method('publishShippedUpdate');

		$this->flows()->update(['uuid' => 'flow-1'], new ExampleSetRow('app', 'k', self::valid(['name' => 'X', 'publish' => false])));
	}

	public function testUpdateReportsAFailedSave(): void {
		$this->mapper->method('findByUuid')->willReturn(self::storedFlow());
		$this->mapper->method('update')->willThrowException(new \RuntimeException('locked'));

		$this->assertSame(
			['error' => 'locked', 'entry' => null],
			$this->flows()->update(['uuid' => 'flow-1'], new ExampleSetRow('app', 'k', self::valid(['name' => 'X'])))
		);
	}

	public function testLostFollowsWhetherTheFlowExists(): void {
		$this->mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid): Flow {
				if ($uuid !== 'flow-1') {
					throw new DoesNotExistException('gone');
				}

				return self::storedFlow();
			}
		);

		$this->assertFalse($this->flows()->lost(['uuid' => 'flow-1']));
		$this->assertTrue($this->flows()->lost(['uuid' => 'flow-2']));
	}

	public function testRevertDeletesTheStoredFlow(): void {
		$flow = self::storedFlow();
		$this->mapper->method('findByUuid')->willReturn($flow);
		$this->flowService->expects($this->once())->method('deleteStored')->with($flow);

		$this->assertSame('removed', $this->flows()->revert(['uuid' => 'flow-1']));
	}

	public function testRevertOfAGoneFlowCountsAsRemoved(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->flowService->expects($this->never())->method('deleteStored');

		$this->assertSame('removed', $this->flows()->revert(['uuid' => 'flow-1']));
	}

	public function testRevertReportsAnyOtherFailure(): void {
		$this->mapper->method('findByUuid')->willReturn(self::storedFlow());
		$this->flowService->method('deleteStored')->willThrowException(new \RuntimeException('runs still active'));

		$this->assertSame('failed', $this->flows()->revert(['uuid' => 'flow-1']));
	}
}//end class
