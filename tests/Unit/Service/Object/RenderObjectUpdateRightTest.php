<?php

/**
 * The reader's update right on a rendered object (`@self.can.update`).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionCatalogue;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationEntries;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCP\IL10N;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The records list shows an inline editor only where the reader may update the
 * row, so the render layer must say so per row, from the real permission rule.
 */
class RenderObjectUpdateRightTest extends TestCase {

	/**
	 * Render one object of a schema whose update right belongs to `editors`.
	 *
	 * @param array<int, string>       $groups The reader's groups.
	 * @param array<int, string>|string $extend The _extend parameter.
	 * @param string $owner The object's owner.
	 * @param bool   $wireManage Whether the manage decision can be taken (false: its resolver is missing).
	 * @param array<string, mixed>|null $authorization The object's own authorization block.
	 *
	 * @return array The serialised object.
	 */
	private function renderAs(array $groups, array|string $extend, array $actions = [], string $owner = 'bob', bool $wireManage = true, ?array $authorization = null): array {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('case');
		$schema->setProperties(['reference' => ['type' => 'string'], 'assignee' => ['type' => 'string']]);
		$schema->setAuthorization(['read' => ['readers', 'editors'], 'update' => ['editors'], 'take' => ['readers']]);
		$schema->setConfiguration(['x-openregister-action' => $actions]);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);

		$permissions = \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			$userSession,
			$userManager,
			$groupManager,
			$schemaMapper,
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			new NullLogger(),
			$this->createMock(ContainerInterface::class)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$actionList = new ObjectActionList(
			new ObjectActionCatalogue(new ObjectActionDefinitions($this->createMock(RegisterMapper::class)), new ObjectActionInputs(new OasRequestValidator())),
			new ObjectActionGate($permissions, $userSession, $l10n, $this->createMock(ObjectActionElevationEntries::class)),
			$this->createMock(TransitionEngine::class),
			$l10n
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($permissions, $actionList, $userSession, $groupManager, $wireManage) {
				if ($id === PermissionHandlerInterface::class) {
					return $permissions;
				}

				if ($id === IUserSession::class) {
					return $userSession;
				}

				if ($id === IGroupManager::class) {
					return $groupManager;
				}

				if ($id === ObjectScopeResolver::class && $wireManage === true) {
					return new ObjectScopeResolver();
				}

				if ($id === ObjectActionList::class) {
					return $actionList;
				}

				throw new RuntimeException('not wired in this test: '.$id);
			}
		);

		$translationHandler = $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class);
		$translationHandler->method('resolveTranslationsForRender')->willReturnArgument(0);

		$render = new RenderObject(
			stores: new \OCA\OpenRegister\Service\Object\RenderStores(
				mappers: new \OCA\OpenRegister\Service\Object\RenderMappers(
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $schemaMapper, tables: $this->createMock(MagicMapper::class)),
					objectCacheService: $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class)
				),
				files: new \OCA\OpenRegister\Service\Object\RenderFileSources(
					fileMapper: $this->createMock(\OCA\OpenRegister\Files\Db\FileMapper::class),
					fileService: $this->createMock(\OCA\OpenRegister\Service\FileService::class),
					systemTagManager: $this->createMock(\OCP\SystemTag\ISystemTagManager::class),
					systemTagMapper: $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class)
				)
			),
			enrichers: new \OCA\OpenRegister\Service\Object\RenderEnrichers(
				translations: new \OCA\OpenRegister\Service\Object\RenderTranslations(
					translationHandler: $translationHandler,
					translationStatusService: $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
					translationMapper: $this->createMock(\OCA\OpenRegister\Db\TranslationMapper::class),
					languageService: $this->createMock(\OCA\OpenRegister\Service\LanguageService::class)
				),
				derivations: new \OCA\OpenRegister\Service\Object\RenderDerivations(
					computedFieldHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
					calculationEvaluator: $this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
					linkedEntityEnricher: $this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
					urnService: $this->createMock(\OCA\OpenRegister\Service\UrnService::class)
				)
			),
			runtime: new \OCA\OpenRegister\Service\Object\RenderRuntime(
				logger: new NullLogger(),
				extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(objectSourceRegistry: null, fieldEncryptionHandler: null),
				request: null,
				container: $container
			),
			propertyRbacHandler: $this->createMock(\OCA\OpenRegister\Service\PropertyRbacHandler::class)
		);

		$entity = new ObjectEntity();
		$entity->setUuid('5b0c1f0e-0000-4000-8000-000000000001');
		$entity->setSchema('7');
		$entity->setRegister('3');
		$entity->setOwner($owner);
		$entity->setObject(['reference' => 'Z-1']);
		$entity->setAuthorization($authorization);

		return $render->renderEntity(entity: $entity, _extend: $extend)->jsonSerialize();
	}//end renderAs()

	/**
	 * 🔴 An editor reads `can.update: true`.
	 *
	 * @return void
	 */
	public function testAnEditorMayUpdate(): void {
		$rendered = $this->renderAs(['editors'], ['@self.can']);

		$this->assertSame(['update' => true, 'manage' => false], $rendered['@self']['can'] ?? null);
	}//end testAnEditorMayUpdate()

	/**
	 * The owner may update through the owner rule, in no group that says so.
	 *
	 * @return void
	 */
	public function testTheOwnerMayUpdate(): void {
		$rendered = $this->renderAs([], ['@self.can'], owner: 'alice');

		$this->assertSame(['update' => true, 'manage' => true], $rendered['@self']['can'] ?? null);
	}//end testTheOwnerMayUpdate()

	/**
	 * 🔴 A reader reads `can.update: false`, so the list offers no editor.
	 *
	 * @return void
	 */
	public function testAReaderMayNotUpdate(): void {
		$rendered = $this->renderAs(['readers'], ['@self.can']);

		$this->assertSame(['update' => false, 'manage' => false], $rendered['@self']['can'] ?? null);
	}//end testAReaderMayNotUpdate()

	/**
	 * 🔴 The comma-separated spelling works the same.
	 *
	 * @return void
	 */
	public function testTheStringSpellingIsHonoured(): void {
		$rendered = $this->renderAs(['editors'], 'files,@self.can');

		$this->assertSame(['update' => true, 'manage' => false], $rendered['@self']['can'] ?? null);
	}//end testTheStringSpellingIsHonoured()

	/**
	 * Each caller reads the declared actions they may run, next to `update`.
	 *
	 * @return void
	 */
	public function testTheDeclaredActionsAreListedPerCaller(): void {
		$actions = [
			'take' => ['name' => 'Take', 'description' => 'Assign it to yourself.', 'selfAssign' => ['field' => 'assignee']],
			'sendMail' => ['name' => 'Send mail', 'description' => 'A grant only, never run.'],
		];

		$reader = $this->renderAs(['readers'], ['@self.can'], $actions);
		$editor = $this->renderAs(['editors'], ['@self.can'], $actions);

		$this->assertSame(['take' => true, 'update' => false, 'manage' => false], $reader['@self']['can'] ?? null);
		$this->assertSame(['take' => false, 'update' => true, 'manage' => false], $editor['@self']['can'] ?? null);
	}//end testTheDeclaredActionsAreListedPerCaller()

	/**
	 * Without the opt-in no right is computed or claimed.
	 *
	 * @return void
	 */
	public function testNoMarkerWithoutTheExtend(): void {
		$rendered = $this->renderAs(['editors'], []);

		$this->assertArrayNotHasKey('can', $rendered['@self']);
	}//end testNoMarkerWithoutTheExtend()
	/**
	 * An administrator may manage an object somebody else owns.
	 *
	 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
	 *
	 * @return void
	 */
	public function testAnAdministratorMayManage(): void {
		$rendered = $this->renderAs(['admin'], ['@self.can']);

		$this->assertTrue($rendered['@self']['can']['manage'] ?? null);
	}//end testAnAdministratorMayManage()

	/**
	 * A member of the object's owning group may manage it like its named owner.
	 *
	 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
	 *
	 * @return void
	 */
	public function testTheOwningGroupMayManage(): void {
		$rendered = $this->renderAs(['team-a'], ['@self.can'], authorization: ['ownerGroup' => 'team-a']);

		$this->assertTrue($rendered['@self']['can']['manage'] ?? null);
	}//end testTheOwningGroupMayManage()

	/**
	 * 🔴 An editor who is not the owner may update but not manage, and the
	 * watchers endpoint refuses the same caller: the marker never offers what
	 * the endpoint will refuse.
	 *
	 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
	 *
	 * @return void
	 */
	public function testANonOwningEditorMayNotManageAndTheWatchersEndpointAgrees(): void {
		$rendered = $this->renderAs(['editors'], ['@self.can']);
		$this->assertSame(['update' => true, 'manage' => false], $rendered['@self']['can'] ?? null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['editors']);
		$mapper = $this->createMock(\OCA\OpenRegister\Notifications\Db\WatcherMapperInterface::class);
		$mapper->expects($this->never())->method('subscribe');
		$watchers = new \OCA\OpenRegister\Service\Interaction\WatcherService(
			new \OCA\OpenRegister\Service\Interaction\WatcherStore($mapper, $this->createMock(\OCA\OpenRegister\Db\SchemaMapperInterface::class), new NullLogger()),
			new \OCA\OpenRegister\Service\Interaction\WatcherCaller($session, $groupManager),
			$this->createMock(\OCA\OpenRegister\Service\Object\PermissionCheckInterface::class),
			new ObjectScopeResolver()
		);
		$object = new ObjectEntity();
		$object->setUuid('5b0c1f0e-0000-4000-8000-000000000001');
		$object->setOwner('bob');

		$this->expectException(\OCA\OpenRegister\Exception\NotAuthorizedException::class);
		$watchers->addWatcher(new \OCA\OpenRegister\Service\Interaction\AddressedObject($object, '3', '7'), 'carol');
	}//end testANonOwningEditorMayNotManageAndTheWatchersEndpointAgrees()

	/**
	 * When the manage decision cannot be taken it reads false, and `update` still stands.
	 *
	 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
	 *
	 * @return void
	 */
	public function testAManageDecisionThatCannotBeTakenReadsFalse(): void {
		$rendered = $this->renderAs([], ['@self.can'], owner: 'alice', wireManage: false);

		$this->assertSame(['update' => true, 'manage' => false], $rendered['@self']['can'] ?? null);
	}//end testAManageDecisionThatCannotBeTakenReadsFalse()
}//end class
