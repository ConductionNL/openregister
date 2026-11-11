<?php

/**
 * The record a sharing, ownership or read-state route names is the record the RBAC boundary resolves.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectReadStateController;
use OCA\OpenRegister\Controller\Support\ObjectReadStateControllerServices;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingServiceInterface;
use OCA\OpenRegister\Service\Interaction\ReadStateServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectOwnershipControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectSharingControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins, for every per-object route action, that the route's register, schema and id
 * are the ones the object service is aimed at before the object is read, and that an
 * object the boundary does not return is a 404.
 */
final class ObjectEndpointRouteAddressTest extends TestCase {

	/**
	 * The calls the object service saw, in order.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $aimed = [];

	/**
	 * An object service that records where it is aimed and resolves nothing.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		foreach (['setRegister', 'setSchema', 'setObject'] as $method) {
			$objects->method($method)->willReturnCallback(
				function (mixed $value) use ($method, $objects): ObjectService {
					$this->aimed[] = [$method, $value];
					return $objects;
				}
			);
		}

		$objects->method('getObject')->willReturn(null);

		return $objects;
	}//end objectService()

	/**
	 * Every per-object action, with the controller that serves it and its extra route parameter.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
	 */
	public static function actions(): array {
		return [
			'ownership show'          => ['ownership', 'show', []],
			'ownership claim'         => ['ownership', 'claim', []],
			'ownership assign'        => ['ownership', 'assign', []],
			'ownership setOwnerGroup' => ['ownership', 'setOwnerGroup', []],
			'sharing scope'           => ['sharing', 'scope', []],
			'sharing setScope'        => ['sharing', 'setScope', []],
			'sharing shares'          => ['sharing', 'shares', []],
			'sharing createShare'     => ['sharing', 'createShare', []],
			'sharing createLink'      => ['sharing', 'createLink', []],
			'sharing inviteByEmail'   => ['sharing', 'inviteByEmail', []],
			'sharing destroyShare'    => ['sharing', 'destroyShare', ['share-9']],
			'readState show'          => ['readState', 'show', []],
			'readState markRead'      => ['readState', 'markRead', []],
			'readState markUnread'    => ['readState', 'markUnread', []],
		];
	}//end actions()

	/**
	 * The route's register, schema and id are aimed at, in that order, and an unresolved object is a 404.
	 *
	 * @param string             $controller Which controller.
	 * @param string             $action     The route action.
	 * @param array<int, string> $extra      Route parameters after the id.
	 *
	 * @return void
	 *
	 * @dataProvider actions
	 */
	public function testTheRouteAddressIsWhatTheBoundaryResolves(string $controller, string $action, array $extra): void {
		$instance = $this->controller(kind: $controller);

		$response = $instance->$action('reg-a', 'sch-b', 'id-c', ...$extra);

		$this->assertSame(
			[['setRegister', 'reg-a'], ['setSchema', 'sch-b'], ['setObject', 'id-c']],
			$this->aimed
		);
		$this->assertSame(404, $response->getStatus());
	}//end testTheRouteAddressIsWhatTheBoundaryResolves()

	/**
	 * Build one of the three controllers around the recording object service.
	 *
	 * @param string $kind ownership, sharing or readState.
	 *
	 * @return object The controller.
	 */
	private function controller(string $kind): object {
		$request = $this->createMock(IRequest::class);
		$logger  = $this->createMock(LoggerInterface::class);

		if ($kind === 'ownership') {
			return ObjectOwnershipControllerFactory::create(
				'openregister',
				$request,
				$this->objectService(),
				$this->createMock(RegisterMapper::class),
				$this->createMock(SchemaMapper::class),
				$this->createMock(ObjectOwnershipService::class),
				$logger
			);
		}

		if ($kind === 'sharing') {
			return ObjectSharingControllerFactory::create(
				'openregister',
				$request,
				$this->objectService(),
				$this->createMock(RegisterMapperInterface::class),
				$this->createMock(SchemaMapperInterface::class),
				$this->createMock(ObjectSharingService::class),
				$logger
			);
		}

		return new ObjectReadStateController(
			request: $request,
			services: new ObjectReadStateControllerServices(
				readState: $this->createMock(ReadStateServiceInterface::class),
				clearing: $this->createMock(NotificationClearingServiceInterface::class),
				objectService: $this->objectService(),
				logger: $logger
			)
		);
	}//end controller()
}//end class
