<?php

/**
 * The shipped destruction-list register keeps its lists to the archivist
 * group and administrators: the generic objects API answers a signed-in
 * user outside that group with a refusal (403) on read and on write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
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
use Psr\Log\NullLogger;

/**
 * @coversNothing
 */
class DestructionListAuthorizationTest extends TestCase {

	/**
	 * The shipped descriptor.
	 *
	 * @return array<string, mixed>
	 */
	private static function descriptor(): array {
		return json_decode((string) file_get_contents(__DIR__.'/../../../../lib/Settings/destruction_list_register.json'), true);
	}//end descriptor()

	/**
	 * A permission handler on a default (default-open) instance, for a user in the given groups.
	 *
	 * @param array<int, string> $groups The user's groups.
	 *
	 * @return array{0: PermissionHandler, 1: Schema}
	 */
	private function handlerFor(array $groups): array {
		$descriptor = self::descriptor();

		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('destructionList');
		$schema->setAuthorization($descriptor['components']['schemas']['destructionList']['authorization'] ?? null);

		$register = new Register();
		$register->setId(10);
		$register->setAuthorization($descriptor['components']['registers']['destruction-lists']['authorization'] ?? null);
		$register->setConfiguration([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('getFirstRegisterWithSchema')->willReturn(10);
		$registers->method('find')->willReturn($register);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($registers) {
				if ($class === RegisterMapper::class) {
					return $registers;
				}

				throw new \RuntimeException('Not available');
			}
		);

		// Default instance: enforce_default_closed is off, every other flag at its default.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default=false): bool => $default
		);

		$handler = new PermissionHandler(
			$session,
			$users,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			new NullLogger(),
			$container
		);

		return [$handler, $schema];
	}//end handlerFor()

	/**
	 * The descriptor names the group the archival endpoints require.
	 *
	 * @return void
	 */
	public function testTheDescriptorNamesTheArchivistGroup(): void {
		$group = (new \ReflectionClassConstant(ArchivalController::class, 'ARCHIVIST_GROUP'))->getValue();
		$auth  = self::descriptor()['components']['schemas']['destructionList']['authorization'];

		foreach (['read', 'create', 'update', 'delete'] as $action) {
			$this->assertSame([$group, 'admin'], $auth[$action], $action);
		}
	}//end testTheDescriptorNamesTheArchivistGroup()

	/**
	 * A signed-in user outside the archivist group may neither read nor change a list.
	 *
	 * @return void
	 */
	public function testANonArchivistIsRefusedReadAndWrite(): void {
		[$handler, $schema] = $this->handlerFor(groups: ['users']);

		foreach (['read', 'create', 'update', 'delete'] as $action) {
			$this->assertFalse($handler->hasPermission($schema, $action, 'alice'), $action);
		}
	}//end testANonArchivistIsRefusedReadAndWrite()

	/**
	 * An archivist and an administrator may read and change a list.
	 *
	 * @return void
	 */
	public function testAnArchivistAndAnAdminAreAllowed(): void {
		foreach ([['archivaris'], ['admin']] as $groups) {
			[$handler, $schema] = $this->handlerFor(groups: $groups);
			foreach (['read', 'update'] as $action) {
				$this->assertTrue($handler->hasPermission($schema, $action, 'alice'), $groups[0].' '.$action);
			}
		}
	}//end testAnArchivistAndAnAdminAreAllowed()
}//end class
