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
use OCA\OpenRegister\Tests\Unit\Controller\Support\AnonymisationBackendControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditQueryControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditSinkControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\DsarControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ExportProfilesControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowTimerDiagnosticControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\IntegrationsControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\LinkedEntityControllerFactory;
use OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;

/**
 * Pins who may call the audit-sink, audit-query, anonymisation, DSAR, export-profile, timer-diagnostic, integration and linked-entity actions.
 *
 * Every case calls one gated action as an administrator, as a signed-in
 * non-administrator and as nobody, and pins the HTTP status each caller gets.
 * The controllers are built through their test factories, so the same cases
 * run against the flat constructors and against the bundled ones.
 */
final class B15AdminGroupControllersGoldenTest extends TestCase {
	/**
	 * The gated actions and the status each caller gets.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: array<int, mixed>, 3: int, 4: int, 5: int}>
	 */
	public static function caseProvider(): array {
		return [
			'audit sink acknowledge' => [AuditSinkControllerFactory::class, 'acknowledge', [], 200, 403, 403],
			'audit query' => [AuditQueryControllerFactory::class, 'query', [], 200, 403, 401],
			'anonymisation test' => [AnonymisationBackendControllerFactory::class, 'testConnection', [], 400, 403, 403],
			'dsar access' => [DsarControllerFactory::class, 'access', [], 422, 403, 403],
			'dsar portability' => [DsarControllerFactory::class, 'portability', [], 422, 403, 403],
			'dsar erasure' => [DsarControllerFactory::class, 'erasure', [], 422, 403, 403],
			'dsar rectification' => [DsarControllerFactory::class, 'rectification', [], 422, 403, 403],
			'dsar compliance' => [DsarControllerFactory::class, 'compliance', [], 200, 403, 403],
			'export profiles index' => [ExportProfilesControllerFactory::class, 'index', [], 200, 200, 401],
			'export profile show' => [ExportProfilesControllerFactory::class, 'show', [1], 200, 200, 401],
			'export profile create' => [ExportProfilesControllerFactory::class, 'create', [], 201, 201, 401],
			'export profile update' => [ExportProfilesControllerFactory::class, 'update', [1], 200, 200, 401],
			'export profile destroy' => [ExportProfilesControllerFactory::class, 'destroy', [1], 200, 200, 401],
			'export profile contract' => [ExportProfilesControllerFactory::class, 'contract', [], 200, 200, 200],
			'timer explain' => [FlowTimerDiagnosticControllerFactory::class, 'explain', [], 422, 403, 401],
			'integrations index' => [IntegrationsControllerFactory::class, 'index', [], 200, 200, 200],
			'integration show' => [IntegrationsControllerFactory::class, 'show', ['files'], 404, 404, 404],
			'linked object add' => [LinkedEntityControllerFactory::class, 'addObjectLink', ['u', 'mail'], 400, 400, 400],
			'linked object remove' => [LinkedEntityControllerFactory::class, 'removeObjectLink', ['u', 'mail', 'e'], 200, 200, 200],
			'linked register add' => [LinkedEntityControllerFactory::class, 'addRegisterLink', ['u', 'mail'], 400, 403, 403],
			'linked schema add' => [LinkedEntityControllerFactory::class, 'addSchemaLink', ['u', 'mail'], 400, 403, 403],
			'linked reverse lookup' => [LinkedEntityControllerFactory::class, 'reverseLookup', ['mail', 'e'], 200, 403, 403],
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
		$groupManager->method('getUserGroupIds')->willReturn($caller === 'admin' ? ['admin'] : ['staff']);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $caller === 'admin' && $group === 'admin');
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
	 * A stand-in for one collaborator.
	 *
	 * @param string $type The collaborator type.
	 *
	 * @return mixed
	 */
	private function standIn(string $type): mixed {
		return $this->createMock($type);
	}//end standIn()

	/**
	 * Only an administrator sees the operational fields of an integration.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorSeesTheOperationalFields(): void {
		foreach ([true, false] as $admin) {
			$provider = $this->createMock(IntegrationProvider::class);
			$provider->method('getId')->willReturn('files');
			$registry = $this->createMock(IntegrationCatalogueInterface::class);
			$registry->method('list')->willReturn([$provider]);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$userSession = $this->createMock(IUserSession::class);
			$userSession->method('getUser')->willReturn($user);
			$groupManager = $this->createMock(IGroupManager::class);
			$groupManager->method('isAdmin')->willReturn($admin);
			$controller = IntegrationsControllerFactory::create(
				'openregister',
				$this->createMock(IRequest::class),
				$registry,
				$userSession,
				$groupManager,
				$this->createMock(LoggerInterface::class)
			);
			$row = $controller->index()->getData()['integrations'][0];
			$this->assertSame($admin, array_key_exists('requiresPermission', $row));
			$this->assertSame($admin, array_key_exists('authStatus', $row));
		}
	}//end testOnlyAnAdministratorSeesTheOperationalFields()

	/**
	 * The export-profile service is told whether the caller is an administrator.
	 *
	 * @return void
	 */
	public function testExportProfilesPassTheAdminVerdict(): void {
		foreach (['admin' => true, 'member' => false] as $caller => $admin) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$userSession = $this->createMock(IUserSession::class);
			$userSession->method('getUser')->willReturn($user);
			$groupManager = $this->createMock(IGroupManager::class);
			$groupManager->method('isAdmin')->willReturn($admin);
			$groupManager->method('getUserGroupIds')->willReturn($admin === true ? ['admin'] : ['staff']);
			$groupManager->method('isInGroup')->willReturn($admin);
			$service = $this->createMock(ExportProfileService::class);
			$service->expects($this->once())->method('listFor')->with('alice', $admin)->willReturn([]);
			$controller = ExportProfilesControllerFactory::create(
				'openregister',
				$this->createMock(IRequest::class),
				$service,
				$userSession,
				$groupManager,
				$this->createMock(ExportRunRecorder::class)
			);
			$this->assertSame(200, $controller->index()->getStatus(), $caller);
		}
	}//end testExportProfilesPassTheAdminVerdict()
}//end class
