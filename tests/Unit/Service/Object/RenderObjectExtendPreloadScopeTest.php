<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

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
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * An extend preloads its targets from the tables of the schemas the extended properties reference.
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
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses \OCA\OpenRegister\Kernel\Contracts\RenderSelection
 * @uses \OCA\OpenRegister\Service\Archival\ArchivalDecisionSources
 * @uses \OCA\OpenRegister\Service\Archival\ArchivalText
 * @uses \OCA\OpenRegister\Service\Object\EntityRead
 * @uses \OCA\OpenRegister\Service\Object\ExtendDotRequest
 * @uses \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses \OCA\OpenRegister\Service\Object\RecurrenceOccurrences
 * @uses \OCA\OpenRegister\Service\Object\RenderDerivations
 * @uses \OCA\OpenRegister\Service\Object\RenderEnrichers
 * @uses \OCA\OpenRegister\Service\Object\RenderFileSources
 * @uses \OCA\OpenRegister\Service\Object\RenderMappers
 * @uses \OCA\OpenRegister\Service\Object\RenderPath
 * @uses \OCA\OpenRegister\Service\Object\RenderPreloads
 * @uses \OCA\OpenRegister\Service\Object\RenderReadExtensions
 * @uses \OCA\OpenRegister\Service\Object\RenderRequest
 * @uses \OCA\OpenRegister\Service\Object\RenderRun
 * @uses \OCA\OpenRegister\Service\Object\RenderRuntime
 * @uses \OCA\OpenRegister\Service\Object\RenderStores
 * @uses \OCA\OpenRegister\Service\Object\RenderTranslations
 * @uses \OCA\OpenRegister\Service\Object\TranslationScope
 * @uses \OCA\OpenRegister\Service\Relation\DescribeRun
 * @uses \OCA\OpenRegister\Service\Relation\RelationValueReader
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\MagicMappingOptions
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses \OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators
 * @uses \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 */
class RenderObjectExtendPreloadScopeTest extends TestCase {
	private const PARENT = '11111111-1111-4111-8111-111111111111';
	private const TARGET = '22222222-2222-4222-8222-222222222222';
	private const OTHER = '33333333-3333-4333-8333-333333333333';

	/** @var CacheHandler&MockObject */
	private CacheHandler $cache;

	/** @var list<list<int>|null> The schemaIds argument of every preload. */
	private array $scopes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->scopes = [];
		$this->cache = $this->createMock(CacheHandler::class);
		$this->cache->method('preloadObjects')->willReturnCallback(
			function (array $identifiers, ?int $registerId = null, ?array $schemaIds = null): array {
				$this->scopes[] = $schemaIds;
				return [];
			}
		);
	}

	/**
	 * The parent object: one reference by slug, a list by id, and a property without a `$ref`.
	 *
	 * @return ObjectEntity
	 */
	private function parent(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::PARENT);
		$object->setRegister('1');
		$object->setSchema('1');
		$object->setObject(['status' => self::TARGET, 'tags' => [self::OTHER], 'free' => self::OTHER]);

		return $object;
	}

	/**
	 * A schema with an id and properties.
	 *
	 * @param int   $id         The id.
	 * @param array $properties The properties.
	 *
	 * @return Schema
	 */
	private static function schema(int $id, array $properties = []): Schema {
		$schema = new Schema();
		(new ReflectionProperty(Schema::class, 'id'))->setValue($schema, $id);
		$schema->setProperties($properties);

		return $schema;
	}

	/**
	 * A renderer whose schema mapper knows the parent schema (1), `statusType` (7) and schema 8.
	 *
	 * @return RenderObject
	 */
	private function renderer(): RenderObject {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id): Schema {
				return match ((string)$id) {
					'1' => self::schema(
						1,
						[
							'status' => ['type' => 'string', '$ref' => 'statusType'],
							'tags' => ['type' => 'array', 'items' => ['type' => 'string', '$ref' => '8']],
							'free' => ['type' => 'string'],
						]
					),
					'statusType' => self::schema(7),
					'8' => self::schema(8),
					default => throw new DoesNotExistException('no schema ' . $id),
				};
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
			'objectEntityMapper' => $this->createMock(MagicMapper::class),
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

		return new RenderObject(
			stores: new \OCA\OpenRegister\Service\Object\RenderStores(
				mappers: new \OCA\OpenRegister\Service\Object\RenderMappers(
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
						registers: $dependencies['registerMapper'],
						schemas: $dependencies['schemaMapper'],
						tables: $dependencies['objectEntityMapper']
					),
					objectCacheService: $dependencies['objectCacheService']
				),
				files: new \OCA\OpenRegister\Service\Object\RenderFileSources(
					fileMapper: $dependencies['fileMapper'],
					fileService: $dependencies['fileService'],
					systemTagManager: $dependencies['systemTagManager'],
					systemTagMapper: $dependencies['systemTagMapper']
				)
			),
			enrichers: new \OCA\OpenRegister\Service\Object\RenderEnrichers(
				translations: new \OCA\OpenRegister\Service\Object\RenderTranslations(
					translationHandler: $dependencies['translationHandler'],
					translationStatusService: $dependencies['translationStatusService'],
					translationMapper: $dependencies['translationMapper'],
					languageService: $dependencies['languageService']
				),
				derivations: new \OCA\OpenRegister\Service\Object\RenderDerivations(
					computedFieldHandler: $dependencies['computedFieldHandler'],
					calculationEvaluator: $dependencies['calculationEvaluator'],
					linkedEntityEnricher: $dependencies['linkedEntityEnricher'],
					urnService: $dependencies['urnService']
				)
			),
			runtime: new \OCA\OpenRegister\Service\Object\RenderRuntime(
				logger: $dependencies['logger'],
				extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(),
				container: $dependencies['container']
			),
			propertyRbacHandler: $dependencies['propertyRbacHandler']
		);
	}

	/**
	 * The schemas each preload was scoped to, after a page render with this extend.
	 *
	 * @param array $extend The extend list.
	 *
	 * @return list<list<int>|null>
	 */
	private function pageScopes(array $extend): array {
		$this->renderer()->renderEntities(entities: [$this->parent()], _extend: $extend);

		return $this->scopes;
	}

	public function testAPageLoadsAReferenceFromItsTargetSchemaOnly(): void {
		$this->assertSame([[7]], $this->pageScopes(['status']));
	}

	public function testAListReferenceAddsTheSchemaOfItsItems(): void {
		$this->assertSame([[7, 8]], $this->pageScopes(['status', 'tags']));
	}

	public function testANestedExtendAndSelfMetadataScopeByTheTopProperty(): void {
		$this->assertSame([[7]], $this->pageScopes(['status.name', '@self.schema']));
	}

	public function testAPropertyWithoutARefLeavesTheLoadUnscoped(): void {
		$this->assertSame([null], $this->pageScopes(['status', 'free']));
	}

	public function testExtendingEverythingLeavesTheLoadUnscoped(): void {
		$this->assertSame([], array_values(array_filter($this->pageScopes(['all']), static fn ($scope): bool => $scope !== null)));
	}

	public function testASingleRenderScopesItsOwnPreload(): void {
		$this->renderer()->renderEntity(entity: $this->parent(), _extend: ['status']);

		$this->assertSame([[7]], $this->scopes);
	}
}
