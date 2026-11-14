<?php

/**
 * A move names its schemas by slug inside the registers it names (register-scoped-schema-slug-resolution, class (b)).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @uses \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\Object\MoveAddress
 * @uses \OCA\OpenRegister\Service\Object\ObjectMove
 * @uses \OCA\OpenRegister\Service\WriteCause
 */
class ObjectsControllerSchemaSlugScopeTest extends TestCase {

	/**
	 * The schema ids the rights were asked for, with the action.
	 *
	 * @var list<array{0: string, 1: int|null}>
	 */
	private array $asked = [];

	/**
	 * A move whose schemas are named by a slug two registers share resolves each inside its register.
	 *
	 * Register 1 (hermiq) carries schema 36 `agent`; register 2 (openbuild) carries schema 22,
	 * also `agent`, which the global lookup returns first. Before the fix both ends read 22.
	 *
	 * @return void
	 */
	public function testASharedSlugResolvesInsideTheNamedRegister(): void {
		$status = $this->move(registers: ['1' => [36], '2' => [22, 23]], source: ['1', 'agent'], target: ['2', 'agent']);

		$this->assertSame(200, $status);
		$this->assertSame([['update', 36], ['create', 22]], $this->asked);
		$this->assertSame([['agent', [36]], ['agent', [22, 23]]], $this->scopedLookups);
	}//end testASharedSlugResolvesInsideTheNamedRegister()

	/**
	 * A slug the register does not carry, and a numeric id, fall through to the global lookup.
	 *
	 * @return void
	 */
	public function testNumericIdsAndUnknownSlugsFallThroughToTheGlobalLookup(): void {
		$status = $this->move(registers: ['1' => [36], '2' => [23]], source: ['1', '36'], target: ['2', 'agent']);

		$this->assertSame(200, $status);
		$this->assertSame([['update', 36], ['create', 22]], $this->asked);
		$this->assertSame([['agent', [23]]], $this->scopedLookups);
		$this->assertSame(['36', 'agent'], $this->globalLookups);
	}//end testNumericIdsAndUnknownSlugsFallThroughToTheGlobalLookup()

	/**
	 * The scoped lookups made: slug and the register's schema ids.
	 *
	 * @var list<array{0: string, 1: array<int, int>}>
	 */
	private array $scopedLookups = [];

	/**
	 * The global lookups made.
	 *
	 * @var list<string|int>
	 */
	private array $globalLookups = [];

	/**
	 * Move object u-1 from the source to the target, holding every right.
	 *
	 * @param array<string, array<int, int>> $registers The schema ids each register carries.
	 * @param array{0: string, 1: string}    $source    The source register and schema.
	 * @param array{0: string, 1: string}    $target    The target register and schema.
	 *
	 * @return int The response status.
	 */
	private function move(array $registers, array $source, array $target): int {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setOwner('anna');
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($object);

		$mover = $this->createMock(MoveObject::class);
		$mover->method('perform')->willReturn(['moved' => true, 'uuid' => 'u-1', 'from' => [], 'to' => [], 'errors' => []]);
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action) : bool {
				$this->asked[] = [$action, $schema->getId()];
				return true;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['targetRegister', '', $target[0]], ['targetSchema', '', $target[1]]]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === \OCA\OpenRegister\Service\Object\MoveObjectInterface::class) ? $mover : $permissions
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function ($id) use ($registers): Register {
				$register = new Register();
				$register->setId((int) $id);
				$register->setSchemas($registers[(string) $id]);
				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findBySlugInIds')->willReturnCallback(
			function (string $slug, array $schemaIds): ?Schema {
				$this->scopedLookups[] = [$slug, $schemaIds];
				foreach ([36, 22] as $agentId) {
					if ($slug === 'agent' && in_array($agentId, $schemaIds, true) === true) {
						$schema = new Schema();
						$schema->setId($agentId);
						return $schema;
					}
				}

				return null;
			}
		);
		$schemas->method('find')->willReturnCallback(
			function ($id): Schema {
				$this->globalLookups[] = $id;
				$schema = new Schema();
				// The global resolver answers openbuild's `agent` (22) for the slug.
				$schema->setId(($id === 'agent') ? 22 : (int) $id);
				return $schema;
			}
		);

		$controller = ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$registerMapper,
			$schemas,
			$this->createMock(AuditTrailMapper::class),
			$objectService,
			$session,
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class),
			windowService: $this->createMock(DeletionWindowService::class)
		);

		return $controller->move($source[0], $source[1], 'u-1')->getStatus();
	}//end move()
}//end class
