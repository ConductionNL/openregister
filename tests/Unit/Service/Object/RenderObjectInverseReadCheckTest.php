<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\LinkedEntityEnricher;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCA\OpenRegister\Service\UrnService;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

/**
 * Inverse properties hold only the objects the caller may read.
 *
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @uses \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses \OCA\OpenRegister\Service\Object\RecurrenceOccurrences
 * @uses \OCA\OpenRegister\Service\Object\VirtualCalculations
 * @uses \OCA\OpenRegister\Service\Object\InverseBatch
 * @uses \OCA\OpenRegister\Service\Object\InverseLookup
 * @uses \OCA\OpenRegister\Service\Object\ExtendTargetReads
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationCache
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationPreload
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationRender
 * @uses \OCA\OpenRegister\Service\Object\InverseRenderSupport
 * @uses \OCA\OpenRegister\Service\Object\InverseSchemaLookups
 * @uses \OCA\OpenRegister\Service\Object\InverseObjectHooks
 * @uses \OCA\OpenRegister\Service\Object\InverseTarget
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver
 * @uses \OCA\OpenRegister\Service\Archival\UnestablishedValues
 * @uses \OCA\OpenRegister\Service\Object\RenderContext
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses \OCA\OpenRegister\Service\Relation\LinkExposure
 * @uses \OCA\OpenRegister\Service\Relation\RelationAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Relation\RelationTypeResolver
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class RenderObjectInverseReadCheckTest extends TestCase {
	private const PARENT = '11111111-1111-4111-8111-111111111111';
	private const OPEN = '22222222-2222-4222-8222-222222222222';
	private const CLOSED = '33333333-3333-4333-8333-333333333333';

	/** @var MagicMapper&MockObject */
	private MagicMapper $mapper;

	/** @var PermissionHandlerInterface&MockObject */
	private PermissionHandlerInterface $permissions;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(MagicMapper::class);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->permissions->method('hasPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ?string $userId, ?string $owner, bool $rbac, ?ObjectEntity $object): bool {
				return $rbac === false || ($action === 'read' && $object?->getUuid() !== self::CLOSED);
			}
		);
	}

	/**
	 * A schema with an id and properties.
	 *
	 * @param int   $id         The schema id.
	 * @param array $properties The properties.
	 *
	 * @return Schema
	 */
	private function schema(int $id, array $properties = []): Schema {
		$schema = new Schema();
		(new ReflectionProperty(Schema::class, 'id'))->setValue($schema, $id);
		$schema->setProperties($properties);

		return $schema;
	}

	/**
	 * An object in the register.
	 *
	 * @param string $uuid   The UUID.
	 * @param string $schema The schema id.
	 * @param array  $data   The object data.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $schema, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema($schema);
		$object->setObject($data);

		return $object;
	}

	/**
	 * The two child objects that point at the parent.
	 *
	 * @return ObjectEntity[]
	 */
	private function children(): array {
		return [
			$this->object(self::OPEN, '2', ['parent' => self::PARENT, 'name' => 'open']),
			$this->object(self::CLOSED, '2', ['parent' => self::PARENT, 'name' => 'closed']),
		];
	}

	/**
	 * A renderer whose parent schema targets the child schema through $ref.
	 *
	 * @param string $ref The reference to the child schema.
	 *
	 * @return RenderObject
	 */
	private function renderer(string $ref = 'child'): RenderObject {
		$parent = $this->schema(1, ['children' => ['type' => 'array', 'items' => ['$ref' => $ref, 'inversedBy' => 'parent']]]);
		$child = $this->schema(2);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static fn ($id): Schema => in_array((string)$id, ['1'], true) ? $parent : $child
		);
		$schemas->method('findAll')->willReturnCallback(
			static fn (...$args): array => ($args[2]['slug'] ?? null) === 'child' ? [$child] : []
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === PermissionHandlerInterface::class) {
					return $this->permissions;
				}

				throw new RuntimeException('not wired: ' . $id);
			}
		);

		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('resolveTranslationsForRender')->willReturnArgument(0);

		return new RenderObject(
			fileMapper: $this->createMock(FileMapper::class),
			objectEntityMapper: $this->mapper,
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $schemas,
			systemTagManager: $this->createMock(ISystemTagManager::class),
			systemTagMapper: $this->createMock(ISystemTagObjectMapper::class),
			objectCacheService: $this->createMock(CacheHandler::class),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
			logger: new NullLogger(),
			fileService: $this->createMock(FileService::class),
			computedFieldHandler: $this->createMock(ComputedFieldHandler::class),
			translationHandler: $translations,
			linkedEntityEnricher: $this->createMock(LinkedEntityEnricher::class),
			calculationEvaluator: $this->createMock(CalculationEvaluator::class),
			urnService: $this->createMock(UrnService::class),
			translationStatusService: $this->createMock(TranslationStatusService::class),
			translationMapper: $this->createMock(TranslationMapper::class),
			languageService: $this->createMock(LanguageService::class),
			container: $container,
		);
	}

	/**
	 * Render the parent through the single-object path.
	 *
	 * @param RenderObject $renderer The renderer.
	 * @param bool         $rbac     The _rbac flag.
	 *
	 * @return array The UUIDs in the inverse property.
	 */
	private function renderedChildren(RenderObject $renderer, bool $rbac = true): array {
		$entity = $this->object(self::PARENT, '1', ['name' => 'parent']);
		$rendered = $renderer->renderEntity(entity: $entity, _extend: ['children'], _rbac: $rbac)->getObject();

		return array_map(static fn (array $child): string => $child['@self']['id'] ?? $child['id'], $rendered['children'] ?? []);
	}

	public function testTheBatchPathLeavesOutWhatTheCallerMayNotRead(): void {
		$this->mapper->method('findByRelationBatchInSchema')->willReturn($this->children());
		$renderer = $this->renderer('2');

		$this->assertSame([self::OPEN], $this->renderedChildren($renderer));
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
		$this->assertArrayHasKey(self::OPEN, $renderer->getObjectsCache());
	}

	public function testASlugTargetLeavesOutWhatTheCallerMayNotRead(): void {
		$this->mapper->method('findByRelationBatchInSchema')->willReturn($this->children());
		$renderer = $this->renderer('child');

		$this->assertSame([self::OPEN], $this->renderedChildren($renderer));
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testAUuidTargetLeavesOutWhatTheCallerMayNotRead(): void {
		$this->mapper->method('findByRelationBatchInSchema')->willReturn($this->children());
		$renderer = $this->renderer('aaaaaaaa-4444-4444-8444-444444444444');

		$this->assertSame([self::OPEN], $this->renderedChildren($renderer));
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testTheFallbackPathLeavesOutWhatTheCallerMayNotRead(): void {
		$this->mapper->method('findByRelationBatchInSchema')->willThrowException(new RuntimeException('batch down'));
		$this->mapper->method('findByRelation')->willReturn($this->children());
		$renderer = $this->renderer('2');

		$this->assertSame([self::OPEN], $this->renderedChildren($renderer));
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testThePagePathLeavesOutWhatTheCallerMayNotRead(): void {
		$this->mapper->method('findByRelationBatchInSchema')->willReturn($this->children());
		$renderer = $this->renderer('2');

		$rendered = $renderer->renderEntities(entities: [$this->object(self::PARENT, '1', ['name' => 'parent'])], _extend: ['children']);

		$this->assertCount(1, $rendered[0]->getObject()['children']);
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testATrustedReadKeepsEveryObject(): void {
		$this->mapper->method('findByRelationBatchInSchema')->willReturn($this->children());

		$this->assertSame([self::OPEN, self::CLOSED], $this->renderedChildren($this->renderer('2'), false));
	}

	public function testATrustedPageReadKeepsEveryObject(): void {
		$this->mapper->method('findByRelationBatchInSchema')->willReturn($this->children());

		$rendered = $this->renderer('2')->renderEntities(
			entities: [$this->object(self::PARENT, '1', ['name' => 'parent'])],
			_extend: ['children'],
			_rbac: false
		);

		$this->assertCount(2, $rendered[0]->getObject()['children']);
	}

	public function testATrustedReadDoesNotFeedALaterUserRead(): void {
		$this->mapper->method('findByRelationBatchInSchema')->willReturn($this->children());
		$renderer = $this->renderer('2');

		$this->assertCount(2, $this->renderedChildren($renderer, false));
		$renderer->clearCache();

		$this->assertSame([self::OPEN], $this->renderedChildren($renderer));
	}

	public function testAUserPageAfterATrustedReadChecksItsPreload(): void {
		$this->mapper->method('findByRelationBatchInSchema')->willReturn($this->children());
		$renderer = $this->renderer('2');

		$this->renderedChildren($renderer, false);
		$renderer->clearCache();
		$renderer->renderEntities(entities: [$this->object(self::PARENT, '1', ['name' => 'parent'])], _extend: ['children']);

		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testAFailedCheckLeavesTheObjectOut(): void {
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->permissions->method('hasPermission')->willThrowException(new RuntimeException('no session'));
		$this->mapper->method('findByRelationBatchInSchema')->willReturn($this->children());

		$this->assertSame([], $this->renderedChildren($this->renderer('2')));
	}
}
