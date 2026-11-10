<?php

/**
 * Golden: a named user's checks decide inside ObjectService::runAs(), also within a system scope.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Pins allowed and refused outcomes of a permission check made as a named user inside a system scope.
 */
class ObjectServiceRunAsInsideSystemScopeGoldenTest extends TestCase {

	private ObjectService $service;

	private PermissionHandler $handler;

	/**
	 * The session double's current user.
	 *
	 * @var IUser|null
	 */
	private ?IUser $current = null;

	/**
	 * Wire one session double into both ObjectService and a real PermissionHandler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->current = null;

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->current);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->current = $user;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user(uid: $uid));

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => ($user->getUID() === 'editor' ? ['editors'] : ['users'])
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);

		$this->handler = PermissionHandlerFactory::create(
			$session,
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class)
		);

		$reflection = new ReflectionClass(ObjectService::class);
		$this->service = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty('userSession');
		$property->setAccessible(true);
		$property->setValue($this->service, $session);
	}//end setUp()

	/**
	 * Build a named user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The double.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * A schema only the `editors` group may create in.
	 *
	 * @return Schema The schema.
	 */
	private function editorsOnly(): Schema {
		$schema = new Schema();
		$schema->setId(77);
		$schema->setAuthorization(['create' => ['editors']]);

		return $schema;
	}//end editorsOnly()

	/**
	 * Ask whether the session subject may create in the schema.
	 *
	 * @return bool The answer.
	 */
	private function mayCreate(): bool {
		return $this->handler->hasPermission(schema: $this->editorsOnly(), action: 'create');
	}//end mayCreate()

	/**
	 * Without a named user the system scope still decides: allowed.
	 *
	 * @return void
	 */
	public function testTheSystemScopeAloneIsAllowed(): void {
		$this->assertTrue(SystemOperationContext::run(fn (): bool => $this->mayCreate()));
	}//end testTheSystemScopeAloneIsAllowed()

	/**
	 * A named user without the right is refused, although a system scope is active.
	 *
	 * @return void
	 */
	public function testANamedUserWithoutTheRightIsRefusedInsideASystemScope(): void {
		$answer = SystemOperationContext::run(
			fn (): bool => $this->service->runAs($this->user(uid: 'alice'), fn (): bool => $this->mayCreate())
		);

		$this->assertFalse($answer);
	}//end testANamedUserWithoutTheRightIsRefusedInsideASystemScope()

	/**
	 * A named user with the right is allowed inside a system scope.
	 *
	 * @return void
	 */
	public function testANamedUserWithTheRightIsAllowedInsideASystemScope(): void {
		$answer = SystemOperationContext::run(
			fn (): bool => $this->service->runAs($this->user(uid: 'editor'), fn (): bool => $this->mayCreate())
		);

		$this->assertTrue($answer);
	}//end testANamedUserWithTheRightIsAllowedInsideASystemScope()

	/**
	 * Outside any scope the named user's checks decide the same way.
	 *
	 * @return void
	 */
	public function testOutsideAScopeTheNamedUserDecides(): void {
		$this->assertFalse($this->service->runAs($this->user(uid: 'alice'), fn (): bool => $this->mayCreate()));
		$this->assertTrue($this->service->runAs($this->user(uid: 'editor'), fn (): bool => $this->mayCreate()));
	}//end testOutsideAScopeTheNamedUserDecides()

	/**
	 * The system scope is back once runAs() returns, also after a throw.
	 *
	 * @return void
	 */
	public function testTheSystemScopeIsRestoredAfterRunAs(): void {
		$after = SystemOperationContext::run(
			function (): array {
				$this->service->runAs($this->user(uid: 'alice'), static fn (): bool => true);
				$afterReturn = SystemOperationContext::isActive();

				try {
					$this->service->runAs(
						$this->user(uid: 'alice'),
						static function (): void {
							throw new RuntimeException('failed');
						}
					);
				} catch (RuntimeException) {
					// Expected.
				}

				return [$afterReturn, SystemOperationContext::isActive()];
			}
		);

		$this->assertSame([true, true], $after);
		$this->assertFalse(SystemOperationContext::isActive());
	}//end testTheSystemScopeIsRestoredAfterRunAs()
}//end class
