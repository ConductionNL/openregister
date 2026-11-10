<?php

/**
 * EndpointsController::allLogs lists only logs of endpoints the caller can see.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\EndpointsController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLog;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Db\EndpointLogScope;
use OCA\OpenRegister\Db\EndpointMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\EndpointService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EndpointsControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EndpointsControllerAllLogsScopeTest extends TestCase {

	/** @var EndpointMapper&MockObject */
	private EndpointMapper $endpointMapper;

	/** @var EndpointLogMapper&MockObject */
	private EndpointLogMapper $endpointLogMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->endpointMapper = $this->createMock(EndpointMapper::class);
		$this->endpointLogMapper = $this->createMock(EndpointLogMapper::class);
	}

	/**
	 * A controller for a caller who is or is not an admin, with the given query.
	 *
	 * @param bool                 $admin  Whether the caller is an admin.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return EndpointsController
	 */
	private function controller(bool $admin, array $params): EndpointsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key) => ($params[$key] ?? null));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anja');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$logger = $this->createMock(LoggerInterface::class);

		return EndpointsControllerFactory::create(
			'openregister',
			$request,
			$this->endpointMapper,
			$this->endpointLogMapper,
			$this->createMock(EndpointService::class),
			new ControllerSession($session, $groups, $logger)
		);
	}

	/**
	 * Endpoints with the given ids, as the organisation-scoped findAll() returns them.
	 *
	 * @param int ...$ids The ids.
	 *
	 * @return array<Endpoint>
	 */
	private function endpoints(int ...$ids): array {
		return array_map(
			static function (int $id): Endpoint {
				$endpoint = new Endpoint();
				$endpoint->setId($id);
				return $endpoint;
			},
			$ids
		);
	}

	public function testANonAdminListsOnlyLogsOfTheirOwnOrganisationsEndpoints(): void {
		$this->endpointMapper->method('findAll')->willReturn($this->endpoints(3, 7));
		$this->endpointLogMapper->expects($this->never())->method('findAll');
		$ownLog = new EndpointLog();
		$this->endpointLogMapper->expects($this->once())
			->method('findPageWithin')
			->with(new EndpointLogScope([3, 7], null), new ListQuery(10, 0))
			->willReturn([[$ownLog], 1]);

		$result = $this->controller(admin: false, params: ['limit' => 10, 'offset' => 0])->allLogs();

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['results' => [$ownLog], 'total' => 1], $result->getData());
	}

	public function testANonAdminFilteringOnAnEndpointIsLimitedToTheirVisibleEndpoints(): void {
		$this->endpointMapper->method('findAll')->willReturn($this->endpoints(3, 7));
		$this->endpointLogMapper->expects($this->never())->method('findByEndpoint');
		$this->endpointLogMapper->expects($this->never())->method('findAll');
		$this->endpointLogMapper->expects($this->once())
			->method('findPageWithin')
			->with(new EndpointLogScope([3, 7], 99), new ListQuery(50, 0))
			->willReturn([[], 0]);

		$result = $this->controller(admin: false, params: ['endpoint_id' => '99'])->allLogs();

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['results' => [], 'total' => 0], $result->getData());
	}

	public function testAnAdminStillListsEveryLogWithoutConsultingTheEndpointList(): void {
		$this->endpointMapper->expects($this->never())->method('findAll');
		$this->endpointLogMapper->expects($this->never())->method('findByEndpoints');
		$logs = [new EndpointLog(), new EndpointLog()];
		$this->endpointLogMapper->method('findAll')->willReturn($logs);

		$result = $this->controller(admin: true, params: [])->allLogs();

		$this->assertSame(['results' => $logs, 'total' => 2], $result->getData());
	}
}
