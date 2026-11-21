<?php

/**
 * Golden tests for the schema analysis a bulk save caches per schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the metadata field map and the inverse relation properties a bulk save reads from a schema.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses   \OCA\OpenRegister\Service\Object\BulkRowChecks
 * @uses   \OCA\OpenRegister\Service\Object\BulkSaveReporting
 * @uses   \OCA\OpenRegister\Service\Object\BulkSaveSession
 * @uses   \OCA\OpenRegister\Service\Object\BulkSaveStores
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkSchemaAnalysis
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\Object\ObjectUpdateOptions
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Service\Object\BulkRowChecks
 * @uses \OCA\OpenRegister\Service\Object\BulkSaveReporting
 * @uses \OCA\OpenRegister\Service\Object\BulkSaveSession
 * @uses \OCA\OpenRegister\Service\Object\BulkSaveStores
 * @uses \OCA\OpenRegister\Service\Object\SaveObjects\BulkSchemaAnalysis
 */
class SaveObjectsSchemaAnalysisGoldenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
			$ref->getProperty($prop)->setValue(null, []);
		}
	}//end setUp()

	/**
	 * Analyse a schema through the bulk save's cached analysis.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array The analysis.
	 */
	private function analyse(Schema $schema): array {
		$handler = new SaveObjects(
			stores: new BulkSaveStores(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $this->createMock(IUserSession::class), organisationService: $this->createMock(OrganisationService::class)),
			reporting: new BulkSaveReporting(logger: $this->createMock(LoggerInterface::class)),
			checks: new BulkRowChecks()
		);

		$method = new ReflectionMethod(SaveObjects::class, 'getSchemaAnalysisWithCache');
		return $method->invoke($handler, $schema);
	}//end analyse()

	public function testMetadataFieldsKeepOnlyConfiguredNonEmptyFields(): void {
		$properties = ['title' => ['type' => 'string']];
		$config = [
			'objectNameField' => 'title',
			'objectDescriptionField' => '',
			'objectSummaryField' => 'intro',
			'objectImageField' => null,
			'objectSlugField' => 'code',
			'unrelated' => 'x',
		];
		$schema = new Schema();
		$schema->setId(11);
		$schema->setHardValidation(true);
		$schema->setProperties($properties);
		$schema->setConfiguration($config);

		$analysis = $this->analyse($schema);

		$this->assertSame(
			[
				'metadataFields' => ['name' => 'title', 'summary' => 'intro', 'slug' => 'code'],
				'inverseProperties' => [],
				'validationRequired' => true,
				'properties' => $properties,
				'configuration' => $schema->getConfiguration(),
			],
			$analysis
		);
	}//end testMetadataFieldsKeepOnlyConfiguredNonEmptyFields()

	public function testInversePropertiesFromPropertyAndItemsLevel(): void {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setHardValidation(false);
		$schema->setProperties(
			[
				'plain' => ['type' => 'string'],
				'owner' => ['type' => 'object', 'inversedBy' => 'owned', 'writeBack' => 'TRUE '],
				'parent' => ['type' => 'object', 'inversedBy' => 'children', 'writeBack' => 0],
				'tags' => ['type' => 'array', 'items' => ['inversedBy' => 'tagged', 'writeBack' => '1']],
				'links' => ['type' => 'array', 'writeBack' => true, 'items' => ['inversedBy' => 'linked', 'writeBack' => 'no']],
				'members' => ['type' => 'array', 'items' => ['inversedBy' => 'groups', 'writeBack' => 1]],
				'both' => ['type' => 'array', 'inversedBy' => 'top', 'writeBack' => false, 'items' => ['inversedBy' => 'inner', 'writeBack' => true]],
				'nothing' => ['type' => 'array', 'items' => ['writeBack' => true]],
			]
		);

		$analysis = $this->analyse($schema);

		$this->assertSame(
			[
				'owner' => ['inversedBy' => 'owned', 'writeBack' => true, 'isArray' => false],
				'parent' => ['inversedBy' => 'children', 'writeBack' => false, 'isArray' => false],
				'tags' => ['inversedBy' => 'tagged', 'writeBack' => false, 'isArray' => true],
				'links' => ['inversedBy' => 'linked', 'writeBack' => true, 'isArray' => true],
				'members' => ['inversedBy' => 'groups', 'writeBack' => true, 'isArray' => true],
				'both' => ['inversedBy' => 'top', 'writeBack' => false, 'isArray' => true],
			],
			$analysis['inverseProperties']
		);
		$this->assertSame([], $analysis['metadataFields']);
		$this->assertFalse($analysis['validationRequired']);
	}//end testInversePropertiesFromPropertyAndItemsLevel()

	public function testTheAnalysisIsCachedPerSchemaId(): void {
		$first = new Schema();
		$first->setId(13);
		$first->setProperties(['a' => ['type' => 'object', 'inversedBy' => 'b']]);
		$second = new Schema();
		$second->setId(13);
		$second->setProperties([]);

		$this->analyse($first);
		$analysis = $this->analyse($second);

		$this->assertSame(['a' => ['inversedBy' => 'b', 'writeBack' => false, 'isArray' => false]], $analysis['inverseProperties']);
	}//end testTheAnalysisIsCachedPerSchemaId()
}//end class
