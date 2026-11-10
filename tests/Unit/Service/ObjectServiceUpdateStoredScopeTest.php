<?php

/**
 * `updateObject()` saves into the register and schema the object is stored in.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use Opis\JsonSchema\ValidationResult;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * An object stored in the register 7 / schema 9 table, updated while the service holds another context.
 *
 * The save handler is the boundary observed: it receives the register and schema the write
 * is validated against and the uuid it looks the existing row up by in that table.
 */
class ObjectServiceUpdateStoredScopeTest extends TestCase {

	private ObjectService $service;

	/** @var MockObject&MagicMapper */
	private $objectMapper;

	/** @var MockObject&SaveObject */
	private $saveHandler;

	private Register $storedRegister;

	private Schema $storedSchema;

	/**
	 * What each save handler call received, by parameter name.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The schema of each validation the save ran.
	 *
	 * @var list<mixed>
	 */
	private array $validatedAgainst = [];

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->saveHandler  = $this->createMock(SaveObject::class);

		$this->storedRegister = new Register();
		$this->storedRegister->setId(7);
		$this->storedSchema = new Schema();
		$this->storedSchema->setId(9);
		$this->storedSchema->setHardValidation(true);

		$valid = $this->createMock(ValidationResult::class);
		$valid->method('isValid')->willReturn(true);
		$validateHandler = $this->createMock(ValidateObject::class);
		$validateHandler->method('validateObject')->willReturnCallback(
			function (array $object, mixed $schema = null) use ($valid): ValidationResult {
				$this->validatedAgainst[] = $schema;

				return $valid;
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			fn (mixed $id): Register => ((string) $id === '7') ? $this->storedRegister : $this->otherRegister()
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			fn (mixed $id): Schema => ((string) $id === '9') ? $this->storedSchema : $this->otherSchema()
		);

		$cascadingHandler = $this->createMock(CascadingHandler::class);
		$cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
			static fn (array $object, mixed $schema = null, ?string $uuid = null): array => [$object, $uuid]
		);

		$this->service = new ObjectService(
			$this->createMock(DataManipulationHandler::class),
			$this->createMock(DeleteObject::class),
			$this->createMock(GetObject::class),
			$this->createMock(PermissionHandler::class),
			$this->createMock(RenderObject::class),
			$this->saveHandler,
			$this->createMock(SaveObjects::class),
			$this->createMock(SearchQueryHandler::class),
			$validateHandler,
			$this->createMock(LockHandler::class),
			$this->createMock(AuditHandler::class),
			$this->createMock(RelationHandler::class),
			$this->createMock(MergeHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(MetadataHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->createMock(QueryHandler::class),
			$this->createMock(RevertHandler::class),
			$this->createMock(UtilityHandler::class),
			$this->createMock(ValidationHandler::class),
			$cascadingHandler,
			$this->createMock(MigrationHandler::class),
			$registerMapper,
			$schemaMapper,
			$this->createMock(ViewMapper::class),
			$this->objectMapper,
			$this->createMock(FileService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SearchTrailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(DateTimeNormalizer::class),
			$this->createMock(IAppContainer::class),
			$this->createMock(ObjectSourceRegistry::class)
		);

		$positions = [];
		foreach ((new ReflectionMethod(SaveObject::class, 'saveObject'))->getParameters() as $parameter) {
			$positions[$parameter->getName()] = $parameter->getPosition();
		}

		$this->saveHandler->method('saveObject')->willReturnCallback(
			function (mixed ...$arguments) use ($positions): ObjectEntity {
				$save = [];
				foreach ($positions as $name => $position) {
					$save[$name] = ($arguments[$position] ?? null);
				}

				$this->saves[] = $save;

				$saved = new ObjectEntity();
				$saved->setUuid($save['uuid']);
				$saved->setRegister((string) $save['register']?->getId());
				$saved->setSchema((string) $save['schema']?->getId());
				$saved->setObject($save['data']);

				return $saved;
			}
		);

	}//end setUp()

	private function otherRegister(): Register {
		$register = new Register();
		$register->setId(1);

		return $register;

	}//end otherRegister()

	private function otherSchema(): Schema {
		$schema = new Schema();
		$schema->setId(2);

		return $schema;

	}//end otherSchema()

	/**
	 * The stored object 'u-1' in register 7 / schema 9, answering to its uuid only.
	 *
	 * @return void
	 */
	private function storeObject(): void {
		$stored = new ObjectEntity();
		$stored->setUuid('u-1');
		$stored->setRegister('7');
		$stored->setSchema('9');
		$stored->setObject(['title' => 'Oud']);

		$this->objectMapper->method('find')->willReturnCallback(
			static function (string|int $identifier) use ($stored): ObjectEntity {
				if ((string) $identifier !== 'u-1') {
					throw new DoesNotExistException('No object answers to ' . $identifier);
				}

				return $stored;
			}
		);

	}//end storeObject()

	/**
	 * Leave the context of an earlier operation on another register and schema behind.
	 *
	 * @return void
	 */
	private function leaveEarlierContext(): void {
		foreach (['currentRegister' => $this->otherRegister(), 'currentSchema' => $this->otherSchema()] as $name => $value) {
			$property = new ReflectionProperty(ObjectService::class, $name);
			$property->setValue($this->service, $value);
		}

	}//end leaveEarlierContext()

	/**
	 * Run the update, keeping only what reached the save handler.
	 *
	 * @return void
	 */
	private function update(string $objectId): void {
		try {
			$this->service->updateObject($objectId, ['title' => 'Nieuw']);
		} catch (DoesNotExistException $exception) {
			throw $exception;
		} catch (Throwable $exception) {
			// The render after the save is mocked out; the save handler call is the subject.
			$this->assertNotEmpty($this->saves, 'the update failed before it reached the save: ' . $exception->getMessage());
		}

	}//end update()

	/**
	 * Assert the one save wrote the stored row, in its own table, validated against its own schema.
	 *
	 * @return void
	 */
	private function assertTheStoredRowIsUpdated(): void {
		$this->assertCount(1, $this->saves, 'one save');
		$save = $this->saves[0];

		$this->assertSame($this->storedRegister, $save['register'], 'the save goes to the register the object is stored in');
		$this->assertSame($this->storedSchema, $save['schema'], 'the save goes to, and validates against, the schema the object is stored in');
		$this->assertSame('u-1', $save['uuid'], 'the save addresses the stored row by its unchanged uuid');
		$this->assertSame([$this->storedSchema], $this->validatedAgainst, 'schema validation runs, against the stored schema');
		$this->assertFalse($save['failIfExists'], 'the write may update the row it found, so it is not a create');

	}//end assertTheStoredRowIsUpdated()

	/**
	 * A context left by an earlier operation does not redirect the update.
	 *
	 * @return void
	 */
	public function testAnUpdateUnderAnotherContextWritesTheStoredTable(): void {
		$this->storeObject();
		$this->leaveEarlierContext();

		$this->update('u-1');

		$this->assertTheStoredRowIsUpdated();

	}//end testAnUpdateUnderAnotherContextWritesTheStoredTable()

	/**
	 * An update without any context set still finds its table.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutContextWritesTheStoredTable(): void {
		$this->storeObject();

		$this->update('u-1');

		$this->assertTheStoredRowIsUpdated();

	}//end testAnUpdateWithoutContextWritesTheStoredTable()

	/**
	 * An identifier nothing answers to is not created by an update.
	 *
	 * @return void
	 */
	public function testAnUpdateOfAnUnknownObjectCreatesNothing(): void {
		$this->storeObject();
		$this->leaveEarlierContext();

		try {
			$this->update('u-unknown');
			$this->fail('an update of an unknown object must not succeed');
		} catch (DoesNotExistException $exception) {
			$this->assertSame([], $this->saves, 'nothing is saved');
		}

	}//end testAnUpdateOfAnUnknownObjectCreatesNothing()
}//end class
