<?php

/**
 * Golden responses of GET /api/objects/{register}/{schema}/{id}/logs for an administrator and a reader.
 *
 * The expected arrays were recorded from ObjectsController::logs() on main before the route moved to
 * AuditTrailController::objectLogs(), with the same request, object, log rows, masker and reader
 * history. The move keeps every key, value, link and pass-through parameter.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Support\AdminFollowsGroups;
use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditTrailControllerFactory;
use PHPUnit\Framework\TestCase;

/**
 * The object API history route answers as it did on ObjectsController.
 */
class AuditTrailObjectLogsRouteGoldenTest extends TestCase {
	use AdminFollowsGroups;


	private $request;

	private $objectService;

	private $userSession;

	private $groupManager;

	private $history;

	private $masker;

	/**
	 * One request, as an administrator or as a reader.
	 *
	 * @param bool $admin Whether the caller is in the admin group.
	 *
	 * @return void
	 */
	private function fixture(bool $admin): void {
		$this->request = $this->createMock(\OCP\IRequest::class);
		$this->request->method('getParams')->willReturn(['_limit' => '2', '_page' => '2', '_search' => 'zoek', 'action' => 'update', 'id' => 'x', '_route' => 'y']);
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/openregister/api/objects/reg/sch/obj-1/logs?_limit=2&_page=2');
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('u');
		$this->userSession = $this->createMock(\OCP\IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager = $this->createMock(\OCP\IGroupManager::class);
		$this->adminFollowsGroups(groupManager: $this->groupManager);
		$this->groupManager->method('getUserGroupIds')->willReturn($admin ? ['users', 'admin'] : ['users']);
		$this->objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$o = new \OCA\OpenRegister\Db\ObjectEntity();
		$o->setRegister('REG');
		$o->setSchema('sch');
		$this->objectService->method('find')->willReturn($o);
		$this->objectService->method('getLogs')->willReturnCallback(fn (...$a) => [['n' => 1, 'f' => $a], ['n' => 2], ['n' => 3], ['n' => 4], ['n' => 5]]);
		$this->history = $this->createMock(\OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders::class);
		$this->history->method('page')->willReturnCallback(fn ($ref, $params) => ['ref' => [$ref->register, $ref->schema, $ref->id], 'params' => $params]);
		$this->masker = $this->createMock(\OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker::class);
		$this->masker->method('mask')->willReturnCallback(fn (array $e) => array_map(fn ($x) => $x + ['masked' => true], $e));
	}//end fixture()

	/**
	 * The route's answer for the fixture.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function answer(): \OCP\AppFramework\Http\JSONResponse {
		return $this->controllerFor()->objectLogs('obj-1', 'reg', 'sch', $this->objectService);
	}//end answer()

	/**
	 * The controller around the fixture.
	 *
	 * @return AuditTrailController
	 */
	private function controllerFor(): AuditTrailController {
		return AuditTrailControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(LogService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(AuditHashService::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(ReadableAuditTrailLister::class),
			$this->createMock(AuditTrailPageService::class),
			$this->history,
			$this->masker
		);
	}//end controllerFor()

	/**
	 * An administrator reads the stored log, masked, paged with links, as before the move.
	 *
	 * @return void
	 */
	public function testAnAdministratorGetsTheRecordedResponse(): void {
		$this->fixture(true);
		$this->history->expects($this->never())->method('page');

		$response = $this->answer();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			array (
			  'results' => 
			  array (
			    0 => 
			    array (
			      'n' => 1,
			      'f' => 
			      array (
			        0 => 'obj-1',
			        1 => 
			        array (
			          '_limit' => '2',
			          '_page' => '2',
			          '_search' => 'zoek',
			          'action' => 'update',
			        ),
			        2 => true,
			        3 => true,
			      ),
			      'masked' => true,
			    ),
			    1 => 
			    array (
			      'n' => 2,
			      'masked' => true,
			    ),
			    2 => 
			    array (
			      'n' => 3,
			      'masked' => true,
			    ),
			    3 => 
			    array (
			      'n' => 4,
			      'masked' => true,
			    ),
			    4 => 
			    array (
			      'n' => 5,
			      'masked' => true,
			    ),
			  ),
			  'total' => 5,
			  'page' => 2,
			  'pages' => 3.0,
			  'limit' => 2,
			  'offset' => 2,
			  'next' => '/index.php/apps/openregister/api/objects/reg/sch/obj-1/logs?_limit=2&_page=3',
			  'prev' => '/index.php/apps/openregister/api/objects/reg/sch/obj-1/logs?_limit=2&_page=1',
			),
			$response->getData()
		);
	}//end testAnAdministratorGetsTheRecordedResponse()

	/**
	 * A reader gets the reader history's page, with the parameters it got before the move.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheRecordedResponse(): void {
		$this->fixture(false);
		$this->objectService->expects($this->never())->method('getLogs');

		$response = $this->answer();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			array (
			  'ref' => 
			  array (
			    0 => 'reg',
			    1 => 'sch',
			    2 => 'obj-1',
			  ),
			  'params' => 
			  array (
			    'limit' => 2,
			    'offset' => 2,
			    'page' => 2,
			    'filters' => 
			    array (
			      '_limit' => '2',
			      '_page' => '2',
			      '_search' => 'zoek',
			      'action' => 'update',
			    ),
			    'sort' => 
			    array (
			    ),
			    'search' => 'zoek',
			  ),
			),
			$response->getData()
		);
	}//end testAReaderGetsTheRecordedResponse()

	/**
	 * The request cases ObjectsControllerRequestParamsGoldenTest records.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function requestCases(): array {
		return ObjectsControllerRequestParamsGoldenTest::configCases();
	}//end requestCases()

	/**
	 * The route reads paging, filters, order and search exactly as the recorded object API listing did.
	 *
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('requestCases')]
	public function testTheRequestReadsAsTheRecordedListing(array $params): void {
		$recorded = json_decode((string)file_get_contents(__DIR__ . '/fixtures/objects-request-params-golden.json'), true);
		$expected = $recorded['config ' . $this->dataName()]['value'];

		$request = $this->createMock(\OCP\IRequest::class);
		$request->method('getParams')->willReturn($params);
		$this->request = $request;
		$this->userSession = $this->createMock(\OCP\IUserSession::class);
		$this->groupManager = $this->createMock(\OCP\IGroupManager::class);
		$this->adminFollowsGroups(groupManager: $this->groupManager);
		$this->history = $this->createMock(\OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders::class);
		$this->masker = null;
		$controller = (new \ReflectionClass(AuditTrailController::class))->getMethod('listingConfig');
		$listing = $controller->invoke($this->controllerFor());
		$actual = json_decode(
			(string)json_encode(
				[
					'limit' => $listing['query']->limit,
					'offset' => $listing['query']->offset,
					'page' => $listing['page'],
					'filters' => $listing['query']->filters,
					'sort' => $listing['query']->sort,
					'_search' => $listing['search'],
				]
			),
			true
		);

		$this->assertSame(
			array_intersect_key($expected, $actual),
			$actual
		);
	}//end testTheRequestReadsAsTheRecordedListing()
}//end class
