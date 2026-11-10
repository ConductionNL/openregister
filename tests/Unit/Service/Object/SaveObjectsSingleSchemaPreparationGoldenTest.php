<?php

/**
 * Golden tests for SaveObjects' single-schema object preparation.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSaveRun;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the @self data each prepared object carries: identity, register, schema, owner, organisation, metadata, relations.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\BulkObjectSlugs
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectDefaults
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectOwnership
 */
class SaveObjectsSingleSchemaPreparationGoldenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
			if ($ref->hasProperty($prop) === true) {
				$ref->getProperty($prop)->setValue(null, []);
			}
		}
	}//end setUp()

	/**
	 * Prepare objects for register 3 / schema 4 as user alice in organisation org-1.
	 *
	 * @param array $objects The raw objects.
	 *
	 * @return array The prepared objects.
	 */
	private function prepare(array $objects): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getOrganisationForNewEntity')->willReturn('org-1');
		$save = $this->createMock(SaveObject::class);
		$save->method('hydrateObjectMetadata')->willReturnCallback(
			static function (ObjectEntity $entity): void {
				$entity->setName('named:' . ($entity->getObject()['title'] ?? '?'));
			}
		);

		$handler = new SaveObjects(
			stores: new BulkSaveStores(objectEntityMapper: $this->createMock(MagicMapper::class), schemaMapper: $this->createMock(SchemaMapper::class), registerMapper: $this->createMock(RegisterMapper::class), saveHandler: $save),
			session: new BulkSaveSession(userSession: $session, organisationService: $organisations),
			reporting: new BulkSaveReporting(logger: $this->createMock(LoggerInterface::class)),
			checks: new BulkRowChecks()
		);

		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(4);
		$schema->setSlug('thing');
		$schema->setProperties(['title' => ['type' => 'string'], 'parent' => ['type' => 'string', 'format' => 'uuid']]);

		$method = new ReflectionMethod(SaveObjects::class, 'prepareSingleSchemaObjectsOptimized');
		$run           = new BulkSaveRun();
		$run->register = $register;
		$run->schema   = $schema;
		return $method->invoke($handler, $objects, $run)[0];
	}//end prepare()

	public function testProvidedIdOwnerOrganisationMetadataAndRelations(): void {
		$parent = '0b9d2f5e-6c1a-4f43-9b8e-3d2f1a0c9e77';
		$prepared = $this->prepare(
			[
				[
					'id' => 'given-id',
					'title' => 'First',
					'parent' => $parent,
					'@self' => ['owner' => 'mallory', 'organisation' => 'org-evil', 'register' => 9, 'schema' => 8],
				],
			]
		);

		$this->assertCount(1, $prepared);
		$row = $prepared[0];
		$this->assertSame('given-id', $row['uuid']);
		$this->assertSame('given-id', $row['id']);
		$this->assertSame(9, $row['register']);
		$this->assertSame(8, $row['schema']);
		$this->assertSame('alice', $row['owner']);
		$this->assertSame('org-1', $row['organisation']);
		$this->assertSame('named:First', $row['name']);
		$this->assertSame(['title' => 'First', 'parent' => $parent], $row['object']);
		$this->assertSame(['parent' => $parent], $row['relations']);
	}//end testProvidedIdOwnerOrganisationMetadataAndRelations()

	public function testBlankIdGetsAGeneratedUuidAndDefaultsFillRegisterAndSchema(): void {
		$prepared = $this->prepare([['title' => 'Second', '@self' => ['id' => '   ']]]);

		$row = $prepared[0];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $row['uuid']);
		$this->assertSame($row['uuid'], $row['id']);
		$this->assertSame(3, $row['register']);
		$this->assertSame(4, $row['schema']);
		$this->assertSame('alice', $row['owner']);
		$this->assertSame([], $row['relations']);
	}//end testBlankIdGetsAGeneratedUuidAndDefaultsFillRegisterAndSchema()
}//end class
