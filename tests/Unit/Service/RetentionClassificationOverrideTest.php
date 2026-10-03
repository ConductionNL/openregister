<?php

declare(strict_types=1);

/**
 * The selectielijst category is the schema's, and an object may override it.
 *
 * Ruben's decision (build-all DECISIONS row 48): "record management and
 * selectielijst should be on schema type, and possible to overwrite per
 * object." These tests run the REAL RetentionService over the REAL
 * SelectielijstResolver, with a store that answers per category, so a test
 * that passes has resolved the row of the category it claims to.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\ClassificationOverride;
use OCA\OpenRegister\Service\Archival\RecordState;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Per-object override of the selectielijst category.
 */
class RetentionClassificationOverrideTest extends TestCase {

	private RetentionService $service;

	/**
	 * The selectielijst rows the store holds, by category.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $rows = [
		'1.1' => ['categorie' => '1.1', 'archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P5Y', 'bron' => 'Selectielijst gemeenten 2020'],
		'2.3' => ['categorie' => '2.3', 'archiefnominatie' => 'bewaren', 'bewaartermijn' => 'P20Y', 'bron' => 'Selectielijst gemeenten 2020'],
	];

	protected function setUp(): void {
		parent::setUp();

		$objectMapper = $this->createMock(MagicMapper::class);
		$settings = $this->createMock(ObjectRetentionHandler::class);
		$logger = $this->createMock(LoggerInterface::class);

		$settings->method('getArchivalSettingsOnly')->willReturn(
			['selectielijstRegister' => 1, 'selectielijstSchema' => 2]
		);

		// The store answers by the category filter, as the real one does, so
		// a resolver that asked for the wrong category gets the wrong row or
		// none at all.
		$objectMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = null): array {
				$category = ($filters['object->categorie'] ?? null);
				if (isset($this->rows[$category]) === false) {
					return [];
				}

				$entry = new ObjectEntity();
				$entry->setObject($this->rows[$category]);

				return [$entry];
			}
		);

		$this->service = new RetentionService(
			$objectMapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$settings,
			$this->createMock(IAppConfig::class),
			$this->createMock(IUserSession::class),
			$logger,
			$this->createMock(RetentionRowScanner::class),
			new ArchiveActionDateCalculator($objectMapper, $logger),
			new SelectielijstResolver(
				$objectMapper,
				$this->createMock(SchemaMapper::class),
				$this->createMock(RegisterMapper::class),
				$settings,
				$logger
			),
		);
	}//end setUp()

	/**
	 * A schema whose category is 1.1 and whose objects may carry their own.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setArchive(
			[
				'enabled' => true,
				'classification' => '1.1',
				'classificationProperty' => 'selectielijstCategorie',
			]
		);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * A new record holding the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The record.
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('dossier-1');
		$object->setObject($data);
		$object->setRetention([]);

		return $object;
	}//end object()

	public function testAnObjectWithoutAnOverrideTakesTheSchemasCategory(): void {
		$retention = $this->service->applyArchivalMetadata($this->object(['titel' => 'x']), $this->schema())->getRetention();

		$this->assertSame('1.1', $retention['classification']);
		$this->assertSame('vernietigen', $retention['archiefnominatie']);
		$this->assertSame('P5Y', $retention['bewaartermijn']);
	}

	public function testAnEmptyOverrideIsNoOverride(): void {
		$retention = $this->service->applyArchivalMetadata(
			$this->object(['selectielijstCategorie' => '  ']),
			$this->schema()
		)->getRetention();

		$this->assertSame('1.1', $retention['classification']);
	}

	public function testAnObjectWithAnOverrideTakesItsOwnCategory(): void {
		$retention = $this->service->applyArchivalMetadata(
			$this->object(['selectielijstCategorie' => '2.3']),
			$this->schema()
		)->getRetention();

		// retention.classification is what the destruction sweep, the list
		// and the certificate read: routing follows the override from here.
		$this->assertSame('2.3', $retention['classification']);
		$this->assertSame('bewaren', $retention['archiefnominatie']);
		$this->assertSame('P20Y', $retention['bewaartermijn']);
	}

	public function testTheEffectiveCategoryIsTheOverrideOrTheSchemas(): void {
		$archive = ['classification' => '1.1', 'classificationProperty' => 'selectielijstCategorie'];

		$this->assertSame('2.3', (new ClassificationOverride())->effective(archive: $archive, data: ['selectielijstCategorie' => '2.3']));
		$this->assertSame('1.1', (new ClassificationOverride())->effective(archive: $archive, data: []));
		// Without a declared property, a same-named field is plain data.
		$this->assertSame('1.1', (new ClassificationOverride())->effective(archive: ['classification' => '1.1'], data: ['selectielijstCategorie' => '2.3']));
	}

	public function testAnInvalidCategoryIsRefused(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('9.9');

		$this->service->guardClassificationOverride(
			schema: $this->schema(),
			data: ['selectielijstCategorie' => '9.9']
		);
	}

	public function testANonTextCategoryIsRefused(): void {
		$this->expectException(ValidationException::class);

		$this->service->guardClassificationOverride(
			schema: $this->schema(),
			data: ['selectielijstCategorie' => ['2.3']]
		);
	}

	public function testAValidOrAbsentOverridePassesTheGuard(): void {
		$this->service->guardClassificationOverride(schema: $this->schema(), data: ['selectielijstCategorie' => '2.3']);
		$this->service->guardClassificationOverride(schema: $this->schema(), data: []);

		// A schema that names no override property ignores the field.
		$plain = new Schema();
		$plain->setArchive(['enabled' => true, 'classification' => '1.1']);
		$this->service->guardClassificationOverride(schema: $plain, data: ['selectielijstCategorie' => '9.9']);

		$this->addToAssertionCount(1);
	}

	public function testAnArchivalAnnotationNamesItsOverridePropertyToo(): void {
		$schema = new Schema();
		$schema->setConfiguration(
			['x-openregister-archival' => ['category' => '1.1', 'categoryProperty' => 'selectielijstCategorie', 'retention' => 'P5Y']]
		);

		$this->assertSame('selectielijstCategorie', (new ClassificationOverride())->propertyOf(archive: ($schema->getArchive() ?? []), configuration: $schema->getConfiguration()));

		$this->expectException(ValidationException::class);
		$this->service->guardClassificationOverride(schema: $schema, data: ['selectielijstCategorie' => '9.9']);
	}

	public function testAChangedOverrideReDerivesAnActiveRecord(): void {
		$schema = $this->schema();
		$object = $this->service->applyArchivalMetadata($this->object([]), $schema);
		$this->assertSame('1.1', $object->getRetention()['classification']);

		$object->setObject(['selectielijstCategorie' => '2.3']);
		$retention = $this->service->applyClassificationOnUpdate(object: $object, schema: $schema, previousData: [])->getRetention();

		$this->assertSame('2.3', $retention['classification']);
		$this->assertSame('bewaren', $retention['archiefnominatie']);
		$this->assertSame('P20Y', $retention['bewaartermijn']);
		$this->assertSame(RecordState::ACTIVE, $retention['archiefstatus']);
	}

	public function testAnUnchangedCategoryLeavesTheRetentionAlone(): void {
		$schema = $this->schema();
		$object = $this->object(['selectielijstCategorie' => '2.3']);
		$object->setRetention(
			[
				'classification' => '2.3',
				'archiefnominatie' => 'bewaren',
				'archiefstatus' => RecordState::SEMI_STATIC,
				'nomination' => ['status' => 'nominated'],
			]
		);

		$before = $object->getRetention();
		$this->assertSame($before, $this->service->applyClassificationOnUpdate(object: $object, schema: $schema, previousData: ['selectielijstCategorie' => '2.3'])->getRetention());
	}

	public function testChangingTheCategoryOfANominatedRecordIsRefused(): void {
		$schema = $this->schema();
		$object = $this->object(['selectielijstCategorie' => '2.3']);
		$object->setRetention(
			[
				'classification' => '1.1',
				'archiefnominatie' => 'vernietigen',
				'archiefstatus' => RecordState::SEMI_STATIC,
				'nomination' => ['status' => 'nominated'],
			]
		);

		$this->expectException(ObjectStateWriteException::class);
		$this->expectExceptionMessage('2.3');

		$this->service->applyClassificationOnUpdate(object: $object, schema: $schema, previousData: []);
	}

	public function testAnInvalidCategoryIsRefusedOnUpdate(): void {
		$object = $this->object(['selectielijstCategorie' => '9.9']);
		$object->setRetention(['classification' => '1.1', 'archiefstatus' => RecordState::ACTIVE]);

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('9.9');

		$this->service->applyClassificationOnUpdate(object: $object, schema: $this->schema(), previousData: []);
	}

	public function testAnEditThatLeavesTheOverrideAloneIsNotRefused(): void {
		// The schema's category moved from 1.1 to 2.3 after this record was
		// nominated. An edit that does not touch the override is not a
		// category change of this record, and must not be refused.
		$schema = new Schema();
		$schema->setArchive(['enabled' => true, 'classification' => '2.3', 'classificationProperty' => 'selectielijstCategorie']);
		$object = $this->object(['titel' => 'nieuw']);
		$object->setRetention(['classification' => '1.1', 'archiefstatus' => RecordState::SEMI_STATIC, 'nomination' => ['status' => 'nominated']]);

		$before = $object->getRetention();
		$after  = $this->service->applyClassificationOnUpdate(object: $object, schema: $schema, previousData: ['titel' => 'oud'])->getRetention();

		$this->assertSame($before, $after);
	}
}
