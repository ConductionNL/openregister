<?php

/**
 * Characterisation test for the Flow operation reads and the link write of FlowLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\FlowLink;
use OCA\OpenRegister\Db\FlowLinkMapper;
use OCA\OpenRegister\Service\FlowLinkPlatform;
use OCA\OpenRegister\Service\FlowLinkService;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what linkOperation, getAvailableOperations and getLinkedOperations
 * return, refuse and log, including the decoding of the events / checks
 * columns.
 */
class FlowLinkServiceOperationsGoldenTest extends TestCase {

	private FlowLinkMapper&MockObject $mapper;

	private IDBConnection&MockObject $db;

	private IAppManager&MockObject $appManager;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private LoggerInterface&MockObject $logger;

	private FlowLinkService $service;

	/**
	 * The iLike patterns the picker query was given.
	 *
	 * @var array<int,mixed>
	 */
	private array $parameters = [];

	/**
	 * Whether the picker query got a name filter.
	 *
	 * @var int
	 */
	private int $filters = 0;

	/**
	 * Logged lines as "level: message".
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(FlowLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndOperation', 'insert'])
			->getMock();
		$this->db           = $this->createMock(IDBConnection::class);
		$this->appManager   = $this->createMock(IAppManager::class);
		$this->userSession  = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger       = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = $level . ': ' . $message;
				}
			);
		}

		$this->service = new FlowLinkService(
			new FlowLinkPlatform($this->mapper, $this->db, $this->appManager),
			$this->userSession,
			$this->groupManager,
			$this->logger
		);
	}//end setUp()

	/**
	 * Log in an administrator with Flow installed.
	 *
	 * @return void
	 */
	private function adminWithFlow(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('root')->willReturn(true);
		$this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);
	}//end adminWithFlow()

	/**
	 * A query builder whose query answers with the given rows (or throws).
	 *
	 * @param array<int,array<string,mixed>>|null $rows The rows; null to throw.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(?array $rows): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('iLike')->willReturn('ilike');
		$expr->method('eq')->willReturn('eq');
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'orderBy', 'where'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('andWhere')->willReturnCallback(
			function () use ($qb) {
				$this->filters++;
				return $qb;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) {
				$this->parameters[] = $value;
				return ':p';
			}
		);
		if ($rows === null) {
			$qb->method('executeQuery')->willThrowException(new RuntimeException('db down'));
			return $qb;
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetch')->willReturn($rows[0] ?? false);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}//end builder()

	/**
	 * The picker rows: decoded, cast and defaulted.
	 *
	 * @return void
	 */
	public function testAvailableOperationsShapeEveryRow(): void {
		$this->adminWithFlow();
		$this->db->method('getQueryBuilder')->willReturn(
			$this->builder(
				[
					['id' => '4', 'class' => 'C', 'name' => 'N', 'entity' => 'E', 'operation' => 'op', 'events' => '["a","b"]', 'checks' => ['x' => 1]],
					['id' => 3, 'events' => '', 'checks' => 'not json'],
					['events' => '"scalar"', 'checks' => 12],
				]
			)
		);

		$this->assertSame(
			[
				['id' => 4, 'name' => 'N', 'class' => 'C', 'entity' => 'E', 'operation' => 'op', 'events' => ['a', 'b'], 'checks' => ['x' => 1], 'enabled' => true],
				['id' => 3, 'name' => '', 'class' => '', 'entity' => '', 'operation' => '', 'events' => [], 'checks' => [], 'enabled' => true],
				['id' => 0, 'name' => '', 'class' => '', 'entity' => '', 'operation' => '', 'events' => [], 'checks' => [], 'enabled' => true],
			],
			$this->service->getAvailableOperations()
		);
		$this->assertSame(0, $this->filters);
		$this->assertSame([], $this->parameters);
	}//end testAvailableOperationsShapeEveryRow()

	/**
	 * A search adds one name filter; an empty search adds none.
	 *
	 * @return void
	 */
	public function testAvailableOperationsFilterOnANonEmptySearch(): void {
		$this->adminWithFlow();
		$this->db->method('getQueryBuilder')->willReturn($this->builder([]));

		$this->assertSame([], $this->service->getAvailableOperations('mail'));
		$this->assertSame([], $this->service->getAvailableOperations(''));
		$this->assertSame(1, $this->filters);
		$this->assertSame(['%mail%'], $this->parameters);
	}//end testAvailableOperationsFilterOnANonEmptySearch()

	/**
	 * A failing query gives an empty picker and one warning.
	 *
	 * @return void
	 */
	public function testAvailableOperationsAreEmptyWhenTheQueryFails(): void {
		$this->adminWithFlow();
		$this->db->method('getQueryBuilder')->willReturn($this->builder(null));

		$this->assertSame([], $this->service->getAvailableOperations('x'));
		$this->assertSame(['warning: FlowLinkService::getAvailableOperations failed: db down'], $this->logs);
	}//end testAvailableOperationsAreEmptyWhenTheQueryFails()

	/**
	 * A linked operation that still exists is refreshed; a gone one is flagged disabled.
	 *
	 * @return void
	 */
	public function testLinkedOperationsAreRefreshedFromFlow(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$link = new FlowLink();
		$link->setObjectUuid('obj');
		$link->setOperationId(5);
		$link->setOperationName('cached');
		$link->setOperationClass('cachedClass');
		$link->setEntityType('cachedEntity');
		$link->setEnabled(true);
		$gone = new FlowLink();
		$gone->setObjectUuid('obj');
		$gone->setOperationId(6);
		$gone->setEnabled(true);
		$this->mapper->method('findByObjectUuid')->willReturn([$link, $gone]);
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->builder([['name' => 'live', 'events' => '[1]', 'operation' => 'cfg']]),
			$this->builder([])
		);

		$rows = $this->service->getLinkedOperations('obj');

		$this->assertSame('live', $rows[0]['operationName']);
		$this->assertSame('cachedClass', $rows[0]['operationClass']);
		$this->assertSame('cachedEntity', $rows[0]['entityType']);
		$this->assertTrue($rows[0]['enabled']);
		$this->assertSame([1], $rows[0]['events']);
		$this->assertSame([], $rows[0]['checks']);
		$this->assertSame('cfg', $rows[0]['operation']);
		$this->assertSame('/index.php/settings/admin/workflow#5', $rows[0]['url']);
		$this->assertFalse($rows[1]['enabled']);
		$this->assertArrayNotHasKey('events', $rows[1]);
		$this->assertSame('/index.php/settings/admin/workflow#6', $rows[1]['url']);
	}//end testLinkedOperationsAreRefreshedFromFlow()

	/**
	 * An operation Flow does not know is refused with 404, nothing is inserted.
	 *
	 * @return void
	 */
	public function testLinkingAnUnknownOperationIsRefused(): void {
		$this->adminWithFlow();
		$this->mapper->method('findByObjectAndOperation')->willReturn(null);
		$this->db->method('getQueryBuilder')->willReturn($this->builder([]));
		$this->mapper->expects($this->never())->method('insert');

		try {
			$this->service->linkOperation((new LinkTarget(objectUuid: 'obj', registerId: 1))->inSchema(schemaId: 2), 77);
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(Exception::class, get_class($e));
			$this->assertSame('Flow operation not found', $e->getMessage());
			$this->assertSame(404, $e->getCode());
		}

		$this->assertSame([77], $this->parameters);
	}//end testLinkingAnUnknownOperationIsRefused()

	/**
	 * A failing operation lookup reads as unknown (404) and logs at debug.
	 *
	 * @return void
	 */
	public function testAFailingOperationLookupReadsAsUnknown(): void {
		$this->adminWithFlow();
		$this->mapper->method('findByObjectAndOperation')->willReturn(null);
		$this->db->method('getQueryBuilder')->willReturn($this->builder(null));

		$this->expectExceptionCode(404);
		try {
			$this->service->linkOperation((new LinkTarget(objectUuid: 'obj', registerId: 1))->inSchema(schemaId: 2), 77);
		} finally {
			$this->assertSame(['debug: fetchOperationRow failed: db down'], $this->logs);
		}
	}//end testAFailingOperationLookupReadsAsUnknown()

	/**
	 * An admin links a known operation: the inserted row carries every field.
	 *
	 * @return void
	 */
	public function testLinkingAKnownOperationInsertsTheRow(): void {
		$this->adminWithFlow();
		$this->mapper->method('findByObjectAndOperation')->with('obj', 77)->willReturn(null);
		$this->db->method('getQueryBuilder')->willReturn(
			$this->builder([['class' => 'Cls', 'name' => 'Nm', 'entity' => 'Ent']])
		);
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$link = $this->service->linkOperation((new LinkTarget(objectUuid: 'obj', registerId: 1))->inSchema(schemaId: 2), 77);

		$row = $link->jsonSerialize();
		$this->assertSame('obj', $row['objectUuid']);
		$this->assertSame(1, $row['registerId']);
		$this->assertSame(2, $row['schemaId']);
		$this->assertSame(77, $row['operationId']);
		$this->assertSame('Cls', $row['operationClass']);
		$this->assertSame('Nm', $row['operationName']);
		$this->assertSame('Ent', $row['entityType']);
		$this->assertTrue($row['enabled']);
		$this->assertSame('root', $row['linkedBy']);
		$this->assertNotNull($row['linkedAt']);
	}//end testLinkingAKnownOperationInsertsTheRow()

	/**
	 * Mutation check: a non-admin is refused before any lookup happens.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefusedBeforeAnyLookup(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->mapper->expects($this->never())->method('findByObjectAndOperation');
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->expectExceptionCode(403);
		$this->service->linkOperation((new LinkTarget(objectUuid: 'obj', registerId: 1))->inSchema(schemaId: 2), 77);
	}//end testANonAdminIsRefusedBeforeAnyLookup()
}//end class
