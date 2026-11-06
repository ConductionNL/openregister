<?php

/**
 * The per-request verdict cache answers per resolved caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A null user means "the session user", and the session can change within a request.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses   \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class PermissionHandlerCallerSwitchCacheTest extends TestCase {

	public function testANullUserIsAnsweredForWhoeverTheSessionHoldsNow(): void {
		$alice = $this->createStub(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$current = $alice;

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(static function () use (&$current): ?IUser {
			return $current;
		});
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturn($alice);
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['editors']);

		$handler = new PermissionHandler(
			userSession: $session,
			userManager: $users,
			groupManager: $groups,
			schemaMapper: $this->createStub(SchemaMapper::class),
			objectEntityMapper: $this->createStub(MagicMapper::class),
			conditionMatcher: $this->createStub(ConditionMatcher::class),
			appConfig: $this->createStub(IAppConfig::class),
			logger: $this->createStub(LoggerInterface::class),
			container: $this->createStub(ContainerInterface::class)
		);

		$schema = new Schema();
		$schema->setId(5);
		$schema->setAuthorization(['read' => ['editors']]);

		$this->assertTrue($handler->hasPermission(schema: $schema, action: 'read'));

		$current = null;
		$this->assertFalse($handler->hasPermission(schema: $schema, action: 'read'));
	}//end testANullUserIsAnsweredForWhoeverTheSessionHoldsNow()
}//end class
