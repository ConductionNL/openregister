<?php

/**
 * Characterisation of DeletedController's list parameters and bulk destroy refusal.
 *
 * Pins every branch of the trash listing's request-parameter extraction
 * (pagination aliases, page-to-offset, sort default, search aliases and the
 * filter set) and the bulk destroy outcome when the delete right is refused,
 * so both can be split into named steps without changing a result.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\DeletedControllerFactory;
use OCA\OpenRegister\Controller\DeletedController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Service\Deletion\DeletionServiceBundle;
use OCA\OpenRegister\Service\Deletion\DestructionServices;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Request parameters of the trash listing, and the bulk destroy refusal.
 *
 * @covers \OCA\OpenRegister\Controller\DeletedController
 */
class DeletedControllerCharacterisationTest extends TestCase {
	private DeletedController $controller;
	private IRequest&MockObject $request;
	private MagicMapper&MockObject $objectMapper;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private PermissionHandler&MockObject $permissionHandler;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);

		$deletion = new DeletionServiceBundle(
			$this->createMock(originalClassName: DeletionWindowService::class),
			$this->createMock(originalClassName: DestroyRightService::class),
			$this->createMock(originalClassName: RetentionClockService::class),
			new DestructionServices($this->createMock(originalClassName: DestructionScopeService::class), $this->createMock(originalClassName: DestructionRecorder::class)),
		);

		$authorizer = new DeletedObjectAuthorizer(
			$this->schemaMapper,
			new CallerContext($this->userSession, $this->groupManager),
			$this->permissionHandler,
		);

		$this->controller = DeletedControllerFactory::create(
			'openregister',
			$this->request,
			$this->objectMapper,
			$this->registerMapper,
			$this->userSession,
			$this->createMock(originalClassName: AuditTrailMapper::class),
			$deletion,
			$authorizer,
			$this->createMock(\OCA\OpenRegister\Service\Object\RenderObject::class)
		);

		// index()/statistics() now scan magic tables directly (BUG-1 fix).
		// PHPUnit auto-returns [] / 0 for the array/int return types, so most
		// param-branch tests need no explicit stub; tests asserting a specific
		// total override countDeletedAcrossAllMagicTables individually.
	}

	/**
	 * Run the private parameter extraction over the given request parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed> The extracted parameters.
	 */
	private function extract(array $params): array {
		$this->request->method('getParams')->willReturn($params);
		$method = new ReflectionMethod(DeletedController::class, 'extractRequestParameters');

		return $method->invoke($this->controller);
	}//end extract()

	/**
	 * No parameters: limit 20, no offset or page, sorted by updated DESC, nothing filtered.
	 *
	 * @return void
	 */
	public function testDefaults(): void {
		$this->assertSame(
			['limit' => 20, 'offset' => null, 'page' => null, 'filters' => [], 'sort' => ['updated' => 'DESC'], 'search' => null],
			$this->extract([])
		);
	}//end testDefaults()

	/**
	 * Plain names win over their underscore aliases, and an offset wins over a page.
	 *
	 * @return void
	 */
	public function testPlainNamesWinAndOffsetWinsOverPage(): void {
		$result = $this->extract(
			[
				'limit'   => '5', '_limit' => '7',
				'offset'  => '3', '_offset' => '9',
				'page'    => '4', '_page' => '8',
				'search'  => 'a', '_search' => 'b',
				'sort'    => 'name', '_sort' => 'uuid',
				'order'   => 'ASC', '_order' => 'DESC',
			]
		);

		$this->assertSame(['limit' => 5, 'offset' => 3, 'page' => 4, 'filters' => [], 'sort' => ['name' => 'ASC'], 'search' => 'a'], $result);
	}//end testPlainNamesWinAndOffsetWinsOverPage()

	/**
	 * Underscore aliases are read when the plain name is absent; a page alone sets the offset.
	 *
	 * @return void
	 */
	public function testUnderscoreAliasesAndPageOffset(): void {
		$result = $this->extract(['_limit' => '10', '_page' => '3', '_search' => 'q', '_sort' => 'uuid', '_order' => 'ASC']);

		$this->assertSame(['limit' => 10, 'offset' => 20, 'page' => 3, 'filters' => [], 'sort' => ['uuid' => 'ASC'], 'search' => 'q'], $result);
	}//end testUnderscoreAliasesAndPageOffset()

	/**
	 * An underscore offset is read, and a null offset falls through to the alias.
	 *
	 * @return void
	 */
	public function testUnderscoreOffsetAndNullPlainValues(): void {
		$result = $this->extract(['offset' => null, '_offset' => '6', 'page' => null, '_page' => '2', 'sort' => 'name']);

		$this->assertSame(6, $result['offset']);
		$this->assertSame(2, $result['page']);
		$this->assertSame(['name' => 'DESC'], $result['sort']);
	}//end testUnderscoreOffsetAndNullPlainValues()

	/**
	 * A page with no offset is converted with the default limit.
	 *
	 * @return void
	 */
	public function testPageWithTheDefaultLimit(): void {
		$result = $this->extract(['page' => '2']);

		$this->assertSame(20, $result['offset']);
		$this->assertSame(2, $result['page']);
	}//end testPageWithTheDefaultLimit()

	/**
	 * Every control and system parameter is kept out of the filters; anything else stays.
	 *
	 * @return void
	 */
	public function testFiltersDropControlParameters(): void {
		$params = array_fill_keys(
			['limit', '_limit', 'offset', '_offset', 'page', '_page', 'search', '_search', 'sort', '_sort', 'order', '_order', '_route', 'id'],
			'1'
		);
		$params['status'] = 'open';
		$params['_schema'] = '12';

		$this->assertSame(['status' => 'open', '_schema' => '12'], $this->extract($params)['filters']);
	}//end testFiltersDropControlParameters()

	/**
	 * A bulk destroy the delete right refuses destroys nothing and counts every row failed.
	 *
	 * @return void
	 */
	public function testABulkDestroyRefusedByTheDeleteRightCountsFailures(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->permissionHandler->method('hasPermission')->willReturn(false);

		$trashed = new ObjectEntity();
		$trashed->setDeleted(['deleted' => '2024-01-01']);
		$trashed->setUuid('uuid-1');
		$trashed->setSchema('10');

		$slim = new ObjectEntity();
		$slim->setUuid('uuid-1');
		$slim->setSchema('10');

		$this->request->method('getParam')->willReturnMap([['ids', [], ['uuid-1', 'uuid-gone']]]);
		$this->objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$slim]);
		$this->objectMapper->method('find')->willReturn($trashed);
		$this->objectMapper->expects($this->never())->method('delete');

		$data = $this->controller->destroyMultiple()->getData();

		$this->assertSame(
			['success' => true, 'deleted' => 0, 'failed' => 2, 'notFound' => 1, 'message' => 'Permanently deleted 0 objects, 2 failed (1 not found)'],
			$data
		);
	}//end testABulkDestroyRefusedByTheDeleteRightCountsFailures()

	/**
	 * A bulk restore whose per-row read throws counts the row failed and keeps going.
	 *
	 * @return void
	 */
	public function testABulkRestoreCountsAThrowingRowFailed(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		$slim = new ObjectEntity();
		$slim->setUuid('uuid-1');

		$this->request->method('getParam')->willReturnMap([['ids', [], ['uuid-1']]]);
		$this->objectMapper->method('findMultipleAcrossAllMagicTables')->willReturn([$slim]);
		$this->objectMapper->method('find')->willThrowException(new \Exception('gone'));

		$this->assertSame(
			['success' => true, 'restored' => 0, 'failed' => 1, 'notFound' => 0, 'message' => 'Restored 0 objects, 1 failed'],
			$this->controller->restoreMultiple()->getData()
		);
	}//end testABulkRestoreCountsAThrowingRowFailed()
}//end class
