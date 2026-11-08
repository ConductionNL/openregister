<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackErrorResponder;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ApiCallersControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ApiSurfaceControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EndpointsControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FederatedConfigControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\MigrationPacksControllerFactory;
use OCP\IL10N;

/**
 * Pins who may call the endpoint, API-caller, API-surface, federated-config and migration-pack actions.
 *
 * Every case calls one gated action as an administrator, as a signed-in
 * non-administrator and as nobody, and pins the HTTP status each caller gets.
 * The controllers are built through their test factories, so the same cases
 * run against the flat constructors and against the bundled ones.
 */
final class B15AdminSessionControllersGoldenTest extends TestCase {
	/**
	 * The gated actions and the status each caller gets.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: array<int, mixed>, 3: int, 4: int, 5: int}>
	 */
	public static function caseProvider(): array {
		return [
			'endpoint create' => [EndpointsControllerFactory::class, 'create', [], 400, 403, 403],
			'endpoint update' => [EndpointsControllerFactory::class, 'update', [1], 200, 403, 403],
			'endpoint destroy' => [EndpointsControllerFactory::class, 'destroy', [1], 204, 403, 403],
			'api callers index' => [ApiCallersControllerFactory::class, 'index', [], 200, 403, 403],
			'api callers write declaration' => [ApiCallersControllerFactory::class, 'writeDeclaration', [], 400, 403, 403],
			'api surface capabilities' => [ApiSurfaceControllerFactory::class, 'capabilities', [], 200, 200, 200],
			'federated trust' => [FederatedConfigControllerFactory::class, 'trust', [], 200, 403, 403],
			'federated set trust' => [FederatedConfigControllerFactory::class, 'setTrust', [], 400, 403, 403],
			'migration pack index' => [MigrationPacksControllerFactory::class, 'index', [], 200, 200, 401],
			'migration pack create' => [MigrationPacksControllerFactory::class, 'create', [], 201, 403, 401],
			'migration pack destroy' => [MigrationPacksControllerFactory::class, 'destroy', [1], 204, 403, 401],
		];
	}//end caseProvider()

	/**
	 * An administrator, a non-administrator and an anonymous caller get the pinned statuses.
	 *
	 * @param class-string $factory The test factory of the controller.
	 * @param string $action The action to call.
	 * @param array<int, mixed> $arguments The action arguments.
	 * @param int $admin The status an administrator gets.
	 * @param int $member The status a signed-in non-administrator gets.
	 * @param int $anonymous The status an anonymous caller gets.
	 *
	 * @dataProvider caseProvider
	 *
	 * @return void
	 */
	public function testCallersGetThePinnedStatus(
		string $factory,
		string $action,
		array $arguments,
		int $admin,
		int $member,
		int $anonymous
	): void {
		$this->assertSame(
			[$admin, $member, $anonymous],
			[
				$this->statusOf($factory, $action, $arguments, 'admin'),
				$this->statusOf($factory, $action, $arguments, 'member'),
				$this->statusOf($factory, $action, $arguments, 'anonymous'),
			]
		);
	}//end testCallersGetThePinnedStatus()

	/**
	 * Call one action as one kind of caller.
	 *
	 * @param class-string $factory The test factory of the controller.
	 * @param string $action The action to call.
	 * @param array<int, mixed> $arguments The action arguments.
	 * @param string $caller admin, member or anonymous.
	 *
	 * @return int The HTTP status of the response.
	 */
	private function statusOf(string $factory, string $action, array $arguments, string $caller): int {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($caller === 'anonymous' ? null : $user);
		$userSession->method('isLoggedIn')->willReturn($caller !== 'anonymous');
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($caller === 'admin');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnArgument(1);
		$request->method('getParams')->willReturn([]);

		$args = [];
		foreach ((new ReflectionMethod($factory, 'args'))->getParameters() as $param) {
			$type = $param->getType();
			$name = 'string';
			if ($type instanceof ReflectionNamedType) {
				$name = $type->getName();
			}

			$args[] = match (true) {
				$param->getName() === 'appName' => 'openregister',
				$name === IRequest::class => $request,
				$name === IUserSession::class => $userSession,
				$name === IGroupManager::class => $groupManager,
				$name === ControllerSession::class => new ControllerSession($userSession, $groupManager, $this->createMock(LoggerInterface::class)),
				default => $this->standIn($name),
			};
		}

		$controller = $factory::create(...$args);
		$response = $controller->$action(...$arguments);
		$this->assertInstanceOf(Response::class, $response);

		return $response->getStatus();
	}//end statusOf()

	/**
	 * A stand-in for one collaborator: a real refusal builder, a mock otherwise.
	 *
	 * @param string $type The collaborator type.
	 *
	 * @return mixed
	 */
	private function standIn(string $type): mixed {
		if ($type === MigrationPackErrorResponder::class) {
			return new MigrationPackErrorResponder($this->createMock(IL10N::class), $this->createMock(LoggerInterface::class));
		}

		return $this->createMock($type);
	}//end standIn()

	/**
	 * A signed-in caller reads the session capabilities, an anonymous one the public capabilities.
	 *
	 * @return void
	 */
	public function testCapabilitiesFollowTheSession(): void {
		foreach (['member' => 'sessionCapabilities', 'anonymous' => 'publicCapabilities'] as $caller => $method) {
			$userSession = $this->createMock(IUserSession::class);
			$userSession->method('isLoggedIn')->willReturn($caller === 'member');
			$capabilities = $this->createMock(\OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService::class);
			$capabilities->expects($this->once())->method($method)->willReturn(['caller' => $caller]);
			$controller = ApiSurfaceControllerFactory::create(
				'openregister',
				$this->createMock(IRequest::class),
				$capabilities,
				$this->createMock(\OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue::class),
				$this->createMock(\OCA\OpenRegister\Service\ApiVersion\ApiContractService::class),
				new ControllerSession($userSession, $this->createMock(IGroupManager::class), $this->createMock(LoggerInterface::class))
			);
			$this->assertSame(['caller' => $caller], $controller->capabilities()->getData());
		}
	}//end testCapabilitiesFollowTheSession()
}//end class
