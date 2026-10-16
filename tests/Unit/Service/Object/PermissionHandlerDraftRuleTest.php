<?php

/**
 * Unit coverage for the draft rule in PermissionHandler::hasPermission().
 *
 * A draft answers to its owner only (decision 180). The list queries keep it
 * from everybody else in SQL, but every check that is handed the stored object
 * (bulk save, bulk delete, the readable audit trail, a transition, a file) is
 * another door onto it. hasPermission() asks DraftStatusPolicy::keptFrom() when
 * it is given an object, for every action, so the doors answer as the read path
 * does: the owner and an administrator pass, a schema that sets
 * `draftsVisible: true` shows its drafts, and the command line without a user is
 * the system.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
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

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Locks the draft rule of PermissionHandler::hasPermission().
 */
class PermissionHandlerDraftRuleTest extends TestCase {

	/**
	 * Build a handler for a caller.
	 *
	 * @param string|null   $uid           The signed-in user, null for nobody.
	 * @param array<string> $groups        The caller's groups.
	 * @param bool          $commandLine   Whether the handler reads as the command line.
	 *
	 * @return PermissionHandler The handler under test.
	 */
	private function handlerFor(?string $uid, array $groups=['staff'], bool $commandLine=false): PermissionHandler {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not available'));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default=false): bool => ($key === 'rbac.inherit_from_public_default' || $default)
		);

		return new class (
			$session,
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$container,
			$commandLine
		) extends PermissionHandler {
			/**
			 * @param mixed $session       The session.
			 * @param mixed $userManager   The user manager.
			 * @param mixed $groupManager  The group manager.
			 * @param mixed $schemaMapper  The schema mapper.
			 * @param mixed $mapper        The object mapper.
			 * @param mixed $matcher       The condition matcher.
			 * @param mixed $appConfig     The app config.
			 * @param mixed $logger        The logger.
			 * @param mixed $container     The container.
			 * @param bool  $commandLine   Whether to read as the command line.
			 */
			public function __construct(
				$session,
				$userManager,
				$groupManager,
				$schemaMapper,
				$mapper,
				$matcher,
				$appConfig,
				$logger,
				$container,
				private readonly bool $commandLine
			) {
				parent::__construct($session, $userManager, $groupManager, $schemaMapper, $mapper, $matcher, $appConfig, $logger, $container);
			}

			/**
			 * @return bool
			 */
			protected function isCommandLine(): bool {
				return $this->commandLine;
			}
		};
	}//end handlerFor()

	/**
	 * A schema whose rules let anybody do anything, so only the draft rule can refuse.
	 *
	 * @param bool $draftsVisible Whether the schema shows its drafts to others.
	 *
	 * @return Schema The schema.
	 */
	private function schema(bool $draftsVisible=false): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setAuthorization(
			[
				'read'   => ['authenticated', 'public'],
				'update' => ['authenticated', 'public'],
				'delete' => ['authenticated', 'public'],
			]
		);
		$schema->setConfiguration(['draftsVisible' => $draftsVisible]);

		return $schema;
	}//end schema()

	/**
	 * A stored object.
	 *
	 * @param string $status The lifecycle status.
	 * @param string $owner  The owner.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $status, string $owner='alice'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setStatus($status);
		$object->setOwner($owner);
		$object->setSchema('5');

		return $object;
	}//end object()

	/**
	 * Every action on somebody else's draft is refused for a non-owner, and passes for the same object once it is not a draft.
	 *
	 * Fails without the draft rule: the schema's rules grant every verb to a
	 * signed-in user.
	 *
	 * @return void
	 */
	public function testSomebodyElsesDraftIsRefusedForEveryAction(): void {
		$handler = $this->handlerFor(uid: 'bob');
		foreach (['read', 'update', 'delete'] as $action) {
			$this->assertFalse(
				$handler->hasPermission(schema: $this->schema(), action: $action, objectOwner: 'alice', object: $this->object(status: 'draft')),
				"bob may not $action alice's draft"
			);
			$this->assertTrue(
				$handler->hasPermission(schema: $this->schema(), action: $action, objectOwner: 'alice', object: $this->object(status: 'active')),
				"bob may $action alice's definitive object"
			);
		}
	}//end testSomebodyElsesDraftIsRefusedForEveryAction()

	/**
	 * The explicit user id is the subject, not the session's user.
	 *
	 * @return void
	 */
	public function testTheExplicitUserIdIsTheSubject(): void {
		$handler = $this->handlerFor(uid: 'alice');
		$this->assertFalse(
			$handler->hasPermission(schema: $this->schema(), action: 'read', userId: 'bob', objectOwner: 'alice', object: $this->object(status: 'draft'))
		);
	}//end testTheExplicitUserIdIsTheSubject()

	/**
	 * The owner keeps their own draft.
	 *
	 * @return void
	 */
	public function testTheOwnerKeepsTheirDraft(): void {
		$handler = $this->handlerFor(uid: 'alice');
		$this->assertTrue($handler->hasPermission(schema: $this->schema(), action: 'read', objectOwner: 'alice', object: $this->object(status: 'draft')));
		$this->assertTrue($handler->hasPermission(schema: $this->schema(), action: 'delete', objectOwner: 'alice', object: $this->object(status: 'draft')));
	}//end testTheOwnerKeepsTheirDraft()

	/**
	 * An administrator reads every draft, as on the read path.
	 *
	 * @return void
	 */
	public function testAnAdministratorIsNotKeptFromADraft(): void {
		$handler = $this->handlerFor(uid: 'root', groups: ['admin']);
		$this->assertTrue($handler->hasPermission(schema: $this->schema(), action: 'read', objectOwner: 'alice', object: $this->object(status: 'draft')));
	}//end testAnAdministratorIsNotKeptFromADraft()

	/**
	 * A schema that sets draftsVisible lets others reach its drafts.
	 *
	 * @return void
	 */
	public function testASchemaWithVisibleDraftsLetsOthersIn(): void {
		$handler = $this->handlerFor(uid: 'bob');
		$this->assertTrue($handler->hasPermission(schema: $this->schema(draftsVisible: true), action: 'read', objectOwner: 'alice', object: $this->object(status: 'draft')));
	}//end testASchemaWithVisibleDraftsLetsOthersIn()

	/**
	 * A check without an object is not a draft question: the rule asks only when it is handed the stored object.
	 *
	 * @return void
	 */
	public function testACheckWithoutAnObjectIsUnchanged(): void {
		$handler = $this->handlerFor(uid: 'bob');
		$this->assertTrue($handler->hasPermission(schema: $this->schema(), action: 'read', objectOwner: 'alice'));
	}//end testACheckWithoutAnObjectIsUnchanged()

	/**
	 * RBAC off and a system operation are not kept from a draft: their caller judges them.
	 *
	 * @return void
	 */
	public function testRbacOffAndTheSystemContextPass(): void {
		$handler = $this->handlerFor(uid: 'bob');
		$draft = $this->object(status: 'draft');
		$this->assertTrue($handler->hasPermission(schema: $this->schema(), action: 'update', objectOwner: 'alice', _rbac: false, object: $draft));
		$this->assertTrue(
			SystemOperationContext::run(fn (): bool => $handler->hasPermission(schema: $this->schema(), action: 'update', objectOwner: 'alice', object: $draft))
		);
	}//end testRbacOffAndTheSystemContextPass()

	/**
	 * An anonymous web caller is kept from a draft, and the command line without a user is the system.
	 *
	 * @return void
	 */
	public function testAnAnonymousWebCallerIsKeptAndTheCommandLineIsNot(): void {
		$draft = $this->object(status: 'draft');
		$this->assertFalse(
			$this->handlerFor(uid: null, commandLine: false)->hasPermission(schema: $this->schema(), action: 'read', objectOwner: 'alice', object: $draft)
		);
		$this->assertTrue(
			$this->handlerFor(uid: null, commandLine: true)->hasPermission(schema: $this->schema(), action: 'read', objectOwner: 'alice', object: $draft)
		);
	}//end testAnAnonymousWebCallerIsKeptAndTheCommandLineIsNot()
}//end class
