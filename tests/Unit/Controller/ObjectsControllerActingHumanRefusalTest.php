<?php

/**
 * The object write and delete routes answer an acting-human refusal with its 403 body (REQ-SAT-006).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers and mock builders use positional args.
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\ObjectDeleteRoute;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ActingHumanRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Drives the shared write refusal mapping and the delete failure mapping directly.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 */
class ObjectsControllerActingHumanRefusalTest extends TestCase {

	public function testAWriteNamingNobodyAnswers403WithItsError(): void {
		$response = $this->writeRefusal(ActingHumanRefusedException::required());

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'acting-human-required'], $response->getData());
	}//end testAWriteNamingNobodyAnswers403WithItsError()

	public function testAWriteNamingAnUndelegatedUserNamesThem(): void {
		$response = $this->writeRefusal(ActingHumanRefusedException::notDelegated(actingUser: 'p.bakker'));

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'acting-human-not-delegated', 'actingUser' => 'p.bakker'], $response->getData());
	}//end testAWriteNamingAnUndelegatedUserNamesThem()

	public function testADeleteRefusalAnswersTheSameBody(): void {
		$controller = $this->controller();
		$method     = new ReflectionMethod(ObjectsController::class, 'deleteFailureResponse');
		$route      = new ObjectDeleteRoute(
			id: 'abc-123',
			register: 'woo',
			schema: 'publicatie',
			objectService: $this->createMock(ObjectService::class)
		);

		$response = $method->invoke($controller, ActingHumanRefusedException::notDelegated(actingUser: 'p.bakker'), $route);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'acting-human-not-delegated', 'actingUser' => 'p.bakker'], $response->getData());
	}//end testADeleteRefusalAnswersTheSameBody()

	/**
	 * The controller's answer to a write refusal.
	 *
	 * @param ActingHumanRefusedException $exception The refusal.
	 *
	 * @return JSONResponse The answer.
	 */
	private function writeRefusal(ActingHumanRefusedException $exception): JSONResponse {
		$method   = new ReflectionMethod(ObjectsController::class, 'writeRefusal');
		$response = $method->invoke($this->controller(), $exception, $this->createMock(ObjectOperationsInterface::class));
		self::assertInstanceOf(JSONResponse::class, $response);

		return $response;
	}//end writeRefusal()

	/**
	 * A controller over doubles.
	 *
	 * @return ObjectsController
	 */
	private function controller(): ObjectsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(originalClassName: IEventDispatcher::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()
}//end class
