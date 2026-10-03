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
use OCA\OpenRegister\Service\Object\RenderObject;
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
	 *
	 * @return array The serialised object.
	 */
	private function renderAs(array $groups, array|string $extend): array {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('case');
		$schema->setProperties(['reference' => ['type' => 'string']]);
		$schema->setAuthorization(['read' => ['readers', 'editors'], 'update' => ['editors']]);

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

		$permissions = new PermissionHandler(
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

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($permissions) {
				if ($id === PermissionHandler::class) {
					return $permissions;
				}

				throw new RuntimeException('not wired in this test: '.$id);
			}
		);

		$translationHandler = $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class);
		$translationHandler->method('resolveTranslationsForRender')->willReturnArgument(0);

		$render = new RenderObject(
			$this->createMock(\OCA\OpenRegister\Db\FileMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(RegisterMapper::class),
			$schemaMapper,
			$this->createMock(\OCP\SystemTag\ISystemTagManager::class),
			$this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\PropertyRbacHandler::class),
			new NullLogger(),
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
			$translationHandler,
			$this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
			$this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
			$this->createMock(\OCA\OpenRegister\Service\UrnService::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
			$this->createMock(\OCA\OpenRegister\Db\TranslationMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\LanguageService::class),
			null,
			null,
			null,
			$container
		);

		$entity = new ObjectEntity();
		$entity->setUuid('5b0c1f0e-0000-4000-8000-000000000001');
		$entity->setSchema('7');
		$entity->setRegister('3');
		$entity->setOwner('bob');
		$entity->setObject(['reference' => 'Z-1']);

		return $render->renderEntity(entity: $entity, _extend: $extend)->jsonSerialize();
	}//end renderAs()

	/**
	 * 🔴 An editor reads `can.update: true`.
	 *
	 * @return void
	 */
	public function testAnEditorMayUpdate(): void {
		$rendered = $this->renderAs(['editors'], ['@self.can']);

		$this->assertSame(['update' => true], $rendered['@self']['can'] ?? null);
	}//end testAnEditorMayUpdate()

	/**
	 * 🔴 A reader reads `can.update: false`, so the list offers no editor.
	 *
	 * @return void
	 */
	public function testAReaderMayNotUpdate(): void {
		$rendered = $this->renderAs(['readers'], ['@self.can']);

		$this->assertSame(['update' => false], $rendered['@self']['can'] ?? null);
	}//end testAReaderMayNotUpdate()

	/**
	 * 🔴 The comma-separated spelling works the same.
	 *
	 * @return void
	 */
	public function testTheStringSpellingIsHonoured(): void {
		$rendered = $this->renderAs(['editors'], 'files,@self.can');

		$this->assertSame(['update' => true], $rendered['@self']['can'] ?? null);
	}//end testTheStringSpellingIsHonoured()

	/**
	 * Without the opt-in no right is computed or claimed.
	 *
	 * @return void
	 */
	public function testNoMarkerWithoutTheExtend(): void {
		$rendered = $this->renderAs(['editors'], []);

		$this->assertArrayNotHasKey('can', $rendered['@self']);
	}//end testNoMarkerWithoutTheExtend()
}//end class
