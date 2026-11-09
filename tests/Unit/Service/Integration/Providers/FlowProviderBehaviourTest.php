<?php

/**
 * The NC Flow (workflowengine) leaf on an object: each linked operation is
 * read back from flow_operations, its JSON events and checks decoded, and a
 * link whose operation is gone stays listed from the link's own fields and
 * reads as disabled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\FlowLink;
use OCA\OpenRegister\Db\FlowLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\Providers\FlowProvider;
use OCA\OpenRegister\Service\Integration\Providers\LeafProviderPlatform;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * FlowProvider.
 */
class FlowProviderBehaviourTest extends TestCase {

	/**
	 * Operation rows by id; a Throwable fails that query.
	 *
	 * @var array<int, array<string, mixed>|\Throwable>
	 */
	private array $operations = [];

	/**
	 * Operation ids queried, with their parameter type.
	 *
	 * @var list<array{mixed, mixed}>
	 */
	private array $queried = [];

	private function provider(bool $installed, array $links): FlowProvider {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('workflowengine')->willReturn($installed);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$mapper = $this->createMock(FlowLinkMapperInterface::class);
		$mapper->method('findByObjectUuid')->with('obj-1')->willReturn($links);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		return new FlowProvider(new LeafProviderPlatform($db, $apps, $l10n), $mapper);
	}//end provider()

	private function queryBuilder(): IQueryBuilder {
		$id = null;
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('from')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->assertSame('flow_operations', $table);
				return $qb;
			}
		);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null) use (&$id): string {
				$id = $value;
				$this->queried[] = [$value, $type];
				return ':id';
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$id): IResult {
				$row = ($this->operations[$id] ?? false);
				if ($row instanceof \Throwable) {
					throw $row;
				}
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturn($row);
				return $result;
			}
		);
		return $qb;
	}//end queryBuilder()

	private function link(int $id, int $operationId): FlowLink {
		$link = new FlowLink();
		$link->setId($id);
		$link->setOperationId($operationId);
		$link->setOperationName('Link name '.$operationId);
		$link->setOperationClass('Link\\Class');
		$link->setEntityType('Link\\Entity');
		return $link;
	}//end link()

	public function testItDescribesItselfAndItsHealthFollowsTheWorkflowEngine(): void {
		$provider = $this->provider(true, []);

		$this->assertSame(['flow', 'Automation', 'RobotOutline', 'workflow'], [$provider->getId(), $provider->getLabel(), $provider->getIcon(), $provider->getGroup()]);
		$this->assertSame('ok', $provider->health()['status']);
		$this->assertSame(
			['status' => 'unavailable', 'authStatus' => 'configured', 'message' => 'NC Flow (workflowengine) app is not installed'],
			$this->provider(false, [])->health()
		);
	}//end testItDescribesItselfAndItsHealthFollowsTheWorkflowEngine()

	public function testWithoutTheWorkflowEngineNothingIsListed(): void {
		$this->assertSame([], $this->provider(false, [$this->link(1, 7)])->list('r', 's', 'obj-1'));
		$this->assertSame([], $this->queried);
	}//end testWithoutTheWorkflowEngineNothingIsListed()

	public function testALiveOperationIsReadBackAndItsJsonFieldsDecoded(): void {
		$this->operations[7] = [
			'id' => 7,
			'class' => 'OCA\\Op',
			'name' => 'Notify',
			'entity' => 'OCA\\Entity',
			'operation' => 'op-config',
			'events' => '["created","updated"]',
			'checks' => [['class' => 'Check']],
		];

		$rows = $this->provider(true, [$this->link(3, 7)])->list('r', 's', 'obj-1');

		$this->assertSame(
			[
				[
					'id' => '7',
					'title' => 'Notify',
					'class' => 'OCA\\Op',
					'entity' => 'OCA\\Entity',
					'operation' => 'op-config',
					'events' => ['created', 'updated'],
					'checks' => [['class' => 'Check']],
					'enabled' => true,
					'url' => '/index.php/settings/admin/workflow#7',
					'linkId' => 3,
					'data' => $this->operations[7],
				],
			],
			$rows
		);
		$this->assertSame([[7, IQueryBuilder::PARAM_INT]], $this->queried);
	}//end testALiveOperationIsReadBackAndItsJsonFieldsDecoded()

	public function testMalformedJsonFieldsDecodeToEmptyLists(): void {
		$this->operations[1] = ['name' => 'A', 'events' => 'not json', 'checks' => ''];
		$this->operations[2] = ['name' => 'B', 'events' => '"a string"', 'checks' => 5];

		$rows = $this->provider(true, [$this->link(1, 1), $this->link(2, 2)])->list('r', 's', 'obj-1');

		$this->assertSame([[], []], array_column($rows, 'events'));
		$this->assertSame([[], []], array_column($rows, 'checks'));
		$this->assertSame(['', ''], array_column($rows, 'operation'));
	}//end testMalformedJsonFieldsDecodeToEmptyLists()

	public function testALinkWhoseOperationIsGoneIsListedFromTheLinkAndDisabled(): void {
		$this->operations[9] = new RuntimeException('no table');
		$bare = new FlowLink();
		$bare->setId(5);
		$bare->setOperationId(8);

		$rows = $this->provider(true, [$this->link(4, 9), $bare])->list('r', 's', 'obj-1');

		$this->assertSame(['Link name 9', ''], array_column($rows, 'title'));
		$this->assertSame(['Link\\Class', ''], array_column($rows, 'class'));
		$this->assertSame(['Link\\Entity', ''], array_column($rows, 'entity'));
		$this->assertSame([false, false], array_column($rows, 'enabled'));
		$this->assertSame([[], []], array_column($rows, 'data'));
		$this->assertSame(['9', '8'], array_column($rows, 'id'));
	}//end testALinkWhoseOperationIsGoneIsListedFromTheLinkAndDisabled()
}//end class
