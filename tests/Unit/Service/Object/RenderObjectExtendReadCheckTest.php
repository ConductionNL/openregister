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
use OCA\OpenRegister\Service\Relation\LinkExposure;
use OCA\OpenRegister\Service\SystemOperationContext;
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
 * An extend shows only the objects the caller may read.
 *
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @covers \OCA\OpenRegister\Service\Object\ExtendTargetReads
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver
 * @uses \OCA\OpenRegister\Service\Archival\UnestablishedValues
 * @uses \OCA\OpenRegister\Service\Object\InverseObjectHooks
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationCache
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationPreload
 * @uses \OCA\OpenRegister\Service\Object\InverseRenderSupport
 * @uses \OCA\OpenRegister\Service\Object\InverseSchemaLookups
 * @uses \OCA\OpenRegister\Service\Object\RenderContext
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses \OCA\OpenRegister\Service\Relation\LinkExposure
 * @uses \OCA\OpenRegister\Service\Relation\RelationAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Relation\RelationTypeResolver
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class RenderObjectExtendReadCheckTest extends TestCase {
	private const PARENT = '11111111-1111-4111-8111-111111111111';
	private const OPEN = '22222222-2222-4222-8222-222222222222';
	private const CLOSED = '33333333-3333-4333-8333-333333333333';
	private const FOREIGN = '44444444-4444-4444-8444-444444444444';

	/** @var MagicMapper&MockObject */
	private MagicMapper $mapper;

	/** @var PermissionHandlerInterface&MockObject */
	private PermissionHandlerInterface $permissions;

	/** @var CacheHandler&MockObject */
	private CacheHandler $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(MagicMapper::class);
		$this->mapper->method('filterReadableUuids')->willReturnCallback(
			static fn (int $registerId, int $schemaId, array $uuids): array => array_values(array_intersect($uuids, [self::OPEN]))
		);
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->permissions->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action, ?string $userId, ?string $owner, bool $rbac, ?ObjectEntity $object): bool
				=> $object?->getUuid() !== self::CLOSED
		);
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturnCallback(
			fn (array $identifiers): array => array_map(fn (string $uuid): ObjectEntity => $this->target($uuid), array_values($identifiers))
		);
		$this->cache->method('getObject')->willReturnCallback(fn (string $uuid): ObjectEntity => $this->target($uuid));
	}

	/**
	 * An object with a UUID and data, in the child schema unless told otherwise.
	 *
	 * @param string $uuid The UUID.
	 * @param array  $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function target(string $uuid, array $data = ['name' => 'target']): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject($data);

		return $object;
	}

	/**
	 * A parent that references the given objects, one by one and in a list.
	 *
	 * @param string   $single The single reference.
	 * @param string[] $list   The list of references.
	 *
	 * @return ObjectEntity
	 */
	private function parent(string $single, array $list): ObjectEntity {
		$object = $this->target(self::PARENT, ['one' => $single, 'many' => $list]);
		$object->setSchema('1');

		return $object;
	}

	/**
	 * A renderer over the mocks of this test.
	 *
	 * @param string[] $exposes The properties the link at `one` exposes, if it declares any.
	 *
	 * @return RenderObject
	 */
	private function renderer(?array $exposes = null): RenderObject {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id) use ($exposes): Schema {
				$schema = new Schema();
				(new ReflectionProperty(Schema::class, 'id'))->setValue($schema, (int)$id);
				$schema->setProperties(
					[
						'secret' => ['type' => 'string', 'writeOnly' => true],
						'one' => ['$ref' => '#/components/schemas/far', 'x-openregister-relation' => ['type' => 'gerelateerd']],
					]
				);
				if ($exposes !== null) {
					$schema->setConfiguration(
						['x-openregister-relation-types' => [['key' => 'gerelateerd', 'label' => 'related to', LinkExposure::KEY => $exposes]]]
					);
				}

				return $schema;
			}
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

		$properties = $this->createMock(PropertyRbacHandler::class);
		$properties->method('stripWriteOnlyProperties')->willReturnCallback(
			static fn (Schema $schema, array $object): array => array_diff_key($object, ['secret' => true])
		);

		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('resolveTranslationsForRender')->willReturnArgument(0);

		$dependencies = [
			'fileMapper' => $this->createMock(FileMapper::class),
			'objectEntityMapper' => $this->mapper,
			'registerMapper' => $this->createMock(RegisterMapper::class),
			'schemaMapper' => $schemas,
			'systemTagManager' => $this->createMock(ISystemTagManager::class),
			'systemTagMapper' => $this->createMock(ISystemTagObjectMapper::class),
			'cacheHandler' => $this->createMock(CacheHandler::class),
			'objectCacheService' => $this->cache,
			'propertyRbacHandler' => $properties,
			'logger' => new NullLogger(),
			'fileService' => $this->createMock(FileService::class),
			'computedFieldHandler' => $this->createMock(ComputedFieldHandler::class),
			'translationHandler' => $translations,
			'linkedEntityEnricher' => $this->createMock(LinkedEntityEnricher::class),
			'calculationEvaluator' => $this->createMock(CalculationEvaluator::class),
			'urnService' => $this->createMock(UrnService::class),
			'translationStatusService' => $this->createMock(TranslationStatusService::class),
			'translationMapper' => $this->createMock(TranslationMapper::class),
			'languageService' => $this->createMock(LanguageService::class),
			'container' => $container,
		];

		// Only what the constructor declares, so the test follows its dependency list.
		$declared = array_column((new \ReflectionMethod(RenderObject::class, '__construct'))->getParameters(), 'name');

		return new RenderObject(...array_intersect_key($dependencies, array_flip($declared)));
	}

	/**
	 * Whether a rendered reference came back as the object rather than the identifier.
	 *
	 * @param mixed $value The rendered value.
	 *
	 * @return bool
	 */
	private static function extended(mixed $value): bool {
		return is_array($value) === true;
	}

	public function testASingleReadKeepsTheIdentifierOfWhatTheCallerMayNotRead(): void {
		$renderer = $this->renderer();
		$data = $renderer->renderEntity(
			entity: $this->parent(self::CLOSED, [self::OPEN, self::CLOSED]),
			_extend: ['one', 'many']
		)->getObject();

		$this->assertSame(self::CLOSED, $data['one']);
		$this->assertTrue(self::extended($data['many'][0]));
		$this->assertSame(self::CLOSED, $data['many'][1]);
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
		$this->assertArrayHasKey(self::OPEN, $renderer->getObjectsCache());
	}

	public function testAPageKeepsTheIdentifierOfWhatTheCallerMayNotRead(): void {
		$renderer = $this->renderer();
		$page = $renderer->renderEntities(
			entities: [$this->parent(self::CLOSED, [self::OPEN, self::CLOSED])],
			_extend: ['one', 'many']
		);

		$data = $page[0]->getObject();
		$this->assertSame(self::CLOSED, $data['one']);
		$this->assertTrue(self::extended($data['many'][0]));
		$this->assertSame(self::CLOSED, $data['many'][1]);
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testAnObjectInTheCacheHandlerIsCheckedToo(): void {
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturn([]);
		$this->cache->method('getObject')->willReturnCallback(fn (string $uuid): ObjectEntity => $this->target($uuid));
		$renderer = $this->renderer();

		$data = $renderer->renderEntity(entity: $this->parent(self::CLOSED, [self::OPEN]), _extend: ['one', 'many'])->getObject();

		$this->assertSame(self::CLOSED, $data['one']);
		$this->assertTrue(self::extended($data['many'][0]));
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testARefusedReferenceIsAskedOnce(): void {
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturn([]);
		$this->cache->method('getObject')->willReturnCallback(fn (string $uuid): ObjectEntity => $this->target($uuid));
		$this->mapper = $this->createMock(MagicMapper::class);
		$this->mapper->expects($this->once())->method('filterReadableUuids')->willReturn([]);

		$data = $this->renderer()->renderEntity(entity: $this->parent(self::CLOSED, [self::CLOSED]), _extend: ['one', 'many'])->getObject();

		$this->assertSame(self::CLOSED, $data['one']);
	}

	public function testAReferenceRefusedUnderOneRuleIsAskedAgainUnderAnother(): void {
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturn([]);
		$this->cache->method('getObject')->willReturnCallback(fn (string $uuid): ObjectEntity => $this->target($uuid));
		$renderer = $this->renderer();
		$renderer->renderEntity(entity: $this->parent(self::CLOSED, []), _extend: ['one']);

		$data = $renderer->renderEntity(entity: $this->parent(self::CLOSED, []), _extend: ['one'], _rbac: false)->getObject();

		$this->assertTrue(self::extended($data['one']));
	}

	public function testATrustedReadKeepsEveryObject(): void {
		$renderer = $this->renderer();
		$data = $renderer->renderEntity(entity: $this->parent(self::CLOSED, [self::CLOSED]), _extend: ['one', 'many'], _rbac: false)->getObject();

		$this->assertTrue(self::extended($data['one']));
		$this->assertTrue(self::extended($data['many'][0]));
		$this->assertArrayHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testATrustedReadKeepsItsRuleThroughANestedExtend(): void {
		$nested = $this->target(self::OPEN, ['deep' => self::CLOSED]);
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturnCallback(
			fn (array $identifiers): array => in_array(self::CLOSED, $identifiers, true) ? [$this->target(self::CLOSED)] : [$nested]
		);

		$data = $this->renderer()->renderEntity(entity: $this->parent(self::OPEN, []), _extend: ['one', 'one.deep'], _rbac: false)->getObject();

		$this->assertTrue(self::extended($data['one']['deep']));
	}

	public function testATrustedReadDoesNotFeedALaterUserRead(): void {
		$renderer = $this->renderer();
		$renderer->renderEntity(entity: $this->parent(self::CLOSED, []), _extend: ['one'], _rbac: false);

		$data = $renderer->renderEntity(entity: $this->parent(self::CLOSED, []), _extend: ['one'])->getObject();

		$this->assertSame(self::CLOSED, $data['one']);
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testARenderAcrossOrganisationsAnswersToTheObjectRule(): void {
		$renderer = $this->renderer();
		$page = $renderer->renderEntities(
			entities: [$this->parent(self::FOREIGN, [self::CLOSED])],
			_extend: ['one', 'many'],
			_multitenancy: false
		);

		$data = $page[0]->getObject();
		$this->assertTrue(self::extended($data['one']));
		$this->assertSame(self::CLOSED, $data['many'][0]);
	}

	public function testAnotherOrganisationsObjectStaysOutUnderTheOrganisationFilter(): void {
		$data = $this->renderer()->renderEntity(entity: $this->parent(self::FOREIGN, []), _extend: ['one'])->getObject();

		$this->assertSame(self::FOREIGN, $data['one']);
	}

	public function testASystemOperationKeepsEveryObject(): void {
		$data = SystemOperationContext::run(
			fn (): array => $this->renderer()->renderEntity(entity: $this->parent(self::CLOSED, []), _extend: ['one'])->getObject()
		);

		$this->assertTrue(self::extended($data['one']));
	}

	public function testALinkThatDeclaresAnExposureShowsNothingToAReaderWithoutTheReadRight(): void {
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturnCallback(
			fn (): array => [$this->target(self::CLOSED, ['status' => 'open', 'note' => 'private'])]
		);
		$renderer = $this->renderer(['status']);

		$data = $renderer->renderEntity(entity: $this->parent(self::CLOSED, [self::CLOSED]), _extend: ['one', 'many'])->getObject();

		$this->assertSame(self::CLOSED, $data['one']);
		$this->assertSame(self::CLOSED, $data['many'][0]);
		$this->assertArrayNotHasKey(self::CLOSED, $renderer->getObjectsCache());
	}

	public function testAPageThroughALinkThatDeclaresAnExposureShowsNothingToAReaderWithoutTheReadRight(): void {
		$renderer = $this->renderer(['name']);

		$page = $renderer->renderEntities(entities: [$this->parent(self::CLOSED, [])], _extend: ['one']);

		$this->assertSame(self::CLOSED, $page[0]->getObject()['one']);
	}

	public function testAnotherOrganisationsObjectThroughALinkThatDeclaresAnExposureStaysAnIdentifier(): void {
		$renderer = $this->renderer(['name']);

		$data = $renderer->renderEntity(entity: $this->parent(self::FOREIGN, []), _extend: ['one'])->getObject();

		$this->assertSame(self::FOREIGN, $data['one']);
	}

	public function testALinkThatDeclaresAnExposureNarrowsWhatAReaderWithTheReadRightSees(): void {
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturnCallback(fn (): array => [$this->named('private name')]);
		$renderer = $this->renderer(['status']);

		$far = $renderer->renderEntity(entity: $this->parent(self::OPEN, []), _extend: ['one'])->getObject()['one'];

		$this->assertSame('open', $far['status']);
		$this->assertSame(LinkExposure::WITHHELD, $far['naam']);
		$this->assertSame(self::OPEN, $far['@self']['id']);
		$this->assertArrayNotHasKey('name', $far['@self']);
		$this->assertStringNotContainsString('private name', (string)json_encode($far));
	}

	public function testAReaderWithTheReadRightFindsTheFullTargetInSelfObjects(): void {
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturnCallback(fn (): array => [$this->named('private name')]);
		$renderer = $this->renderer(['status']);

		$renderer->renderEntity(entity: $this->parent(self::OPEN, []), _extend: ['one']);

		$this->assertSame('private name', $renderer->getObjectsCache()[self::OPEN]['naam']);
	}

	public function testAnExposedNameStaysInTheEnvelope(): void {
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturnCallback(fn (): array => [$this->named('public name')]);
		$renderer = $this->renderer(['naam']);

		$far = $renderer->renderEntity(entity: $this->parent(self::OPEN, []), _extend: ['one'])->getObject()['one'];

		$this->assertSame('public name', $far['@self']['name']);
	}

	/**
	 * A readable target whose `@self.name` is filled from its `naam` property.
	 *
	 * @param string $name The name.
	 *
	 * @return ObjectEntity
	 */
	private function named(string $name): ObjectEntity {
		$object = $this->target(self::OPEN, ['status' => 'open', 'naam' => $name]);
		$object->setName($name);

		return $object;
	}

	/**
	 * What `@self.objects` holds for a target carrying a write-only value.
	 *
	 * @param array $extend The extend list.
	 *
	 * @return array The target as `@self.objects` gives it.
	 */
	private function selfObjectUnder(array $extend): array {
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturnCallback(
			fn (): array => [$this->target(self::OPEN, ['name' => 'target', 'secret' => 'hidden'])]
		);
		$renderer = $this->renderer();
		$renderer->renderEntity(entity: $this->parent(self::OPEN, []), _extend: $extend);

		return $renderer->getObjectsCache()[self::OPEN];
	}

	public function testAnExtendedObjectInSelfObjectsHasNoWriteOnlyValue(): void {
		$object = $this->selfObjectUnder(['one']);

		$this->assertSame('target', $object['name']);
		$this->assertArrayNotHasKey('secret', $object);
	}

	public function testAnObjectThatWasOnlyLoadedIsRenderedForSelfObjects(): void {
		$object = $this->selfObjectUnder(['one.deep']);

		$this->assertSame('target', $object['name']);
		$this->assertArrayNotHasKey('secret', $object);
	}

	public function testARenderedObjectIsNotRenderedAgainForSelfObjects(): void {
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturnCallback(fn (): array => [$this->target(self::OPEN)]);
		$renderer = $this->renderer();
		$renderer->renderEntity(entity: $this->parent(self::OPEN, []), _extend: ['one']);
		$probe = new ReflectionProperty(RenderObject::class, 'objectsCache');
		$cached = $probe->getValue($renderer)[self::OPEN];
		$cached->setObject(['secret' => 'a render would remove this']);

		$this->assertArrayHasKey('secret', $renderer->getObjectsCache()[self::OPEN]);
	}

	public function testEachTableIsAskedOnItsOwn(): void {
		$other = $this->target(self::OPEN);
		$other->setSchema('3');
		$asked = [];
		$this->mapper = $this->createMock(MagicMapper::class);
		$this->mapper->method('filterReadableUuids')->willReturnCallback(
			function (int $registerId, int $schemaId, array $uuids) use (&$asked): array {
				$asked[] = [$registerId, $schemaId, $uuids];

				return $uuids;
			}
		);
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturn([$this->target(self::CLOSED), $other]);

		$this->renderer()->renderEntities(entities: [$this->parent(self::CLOSED, [self::OPEN])], _extend: ['one', 'many']);

		$this->assertSame([[1, 2, [self::CLOSED]], [1, 3, [self::OPEN]]], array_slice($asked, 0, 2));
	}
}
