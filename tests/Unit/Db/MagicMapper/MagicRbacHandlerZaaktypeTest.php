<?php

/**
 * Zaaktype user-override tests for MagicRbacHandler (PHP-side list verdict).
 *
 * The list path's PHP verdict (MagicRbacHandler::hasPermission) MUST agree with
 * PermissionHandler's single-object verdict for `user:<uid>` overrides, so a
 * delegated user sees exactly the rows they may access and no more (rbac-zaaktype,
 * no list-vs-find drift).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * User-override parity tests for the MagicRbacHandler PHP path.
 */
class MagicRbacHandlerZaaktypeTest extends TestCase {
	private MagicRbacHandler $handler;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private ConditionMatcher&MockObject $conditionMatcher;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->conditionMatcher = $this->createMock(ConditionMatcher::class);

		$userManager = $this->createMock(IUserManager::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) {
				throw new \RuntimeException('Not available: ' . $class);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);

		$this->handler = new MagicRbacHandler(
			$this->userSession,
			$this->groupManager,
			$userManager,
			$appConfig,
			$this->conditionMatcher,
			$container,
			$logger
		);
	}

	private function mockUser(?string $uid, array $groups): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}

	private function schema(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setAuthorization($authorization);
		$schema->setTitle('Zaak');
		return $schema;
	}

	public function testUserOverrideGrantsListAccessBareString(): void {
		$this->mockUser('extern-adviseur', []);
		$schema = $this->schema(['read' => ['hr-team', 'user:extern-adviseur']]);

		$this->assertTrue($this->handler->hasPermission($schema, 'read'));
	}

	public function testUserOverrideDeniesOtherUser(): void {
		$this->mockUser('random', ['externals']);
		$schema = $this->schema(['read' => ['hr-team', 'user:extern-adviseur']]);

		$this->assertFalse($this->handler->hasPermission($schema, 'read'));
	}

	public function testUserOverrideNeverGrantsAnonymous(): void {
		$this->mockUser(null, []);
		$schema = $this->schema(['read' => ['user:extern-adviseur']]);

		$this->assertFalse($this->handler->hasPermission($schema, 'read'));
	}

	public function testUserOverrideComplexFormDelegatesMatch(): void {
		$this->mockUser('vervanger-1', []);
		$schema = $this->schema([
			'read' => [['user' => 'vervanger-1', 'match' => ['status' => 'open']]],
		]);

		$this->conditionMatcher->expects($this->once())
			->method('objectMatchesConditions')
			->willReturn(true);

		$this->assertTrue(
			$this->handler->hasPermission($schema, 'read', null, ['status' => 'open'])
		);
	}

	public function testUserOverrideComplexFormWrongUserSkipsMatch(): void {
		$this->mockUser('someone-else', []);
		$schema = $this->schema([
			'read' => [['user' => 'vervanger-1', 'match' => ['status' => 'open']]],
		]);

		$this->conditionMatcher->expects($this->never())->method('objectMatchesConditions');

		$this->assertFalse(
			$this->handler->hasPermission($schema, 'read', null, ['status' => 'open'])
		);
	}
}
