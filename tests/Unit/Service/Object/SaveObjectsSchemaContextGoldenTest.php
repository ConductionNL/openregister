<?php

/**
 * Golden tests for the register and schema context a single-schema bulk save loads.
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
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins loadAndValidateSchemaContext: the ids it reports, what it loads, and what it caches.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SaveObjectsSchemaContextGoldenTest extends TestCase {

	/** @var list<string> */
	private array $loads = [];

	protected function setUp(): void {
		parent::setUp();
		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
			$ref->getProperty($prop)->setValue(null, []);
		}

		$this->loads = [];
	}//end setUp()

	/**
	 * A static cache of SaveObjects.
	 *
	 * @param string $name The property.
	 *
	 * @return array
	 */
	private static function cache(string $name): array {
		return (new ReflectionClass(SaveObjects::class))->getProperty($name)->getValue();
	}//end cache()

	/**
	 * Load the context.
	 *
	 * @param Register|string|int $register  The register argument.
	 * @param Schema|string|int   $schema    The schema argument.
	 * @param array               $registers Loadable registers by id.
	 * @param array               $schemas   Loadable schemas by id.
	 *
	 * @return array
	 */
	private function context(Register|string|int $register, Schema|string|int $schema, array $registers = [], array $schemas = []): array {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function ($id) use ($registers) {
				$this->loads[] = 'register:' . var_export($id, true);
				return $registers[$id];
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id) use ($schemas) {
				$this->loads[] = 'schema:' . var_export($id, true);
				return $schemas[$id];
			}
		);
		$handler = new SaveObjects(
			stores: new BulkSaveStores(objectEntityMapper: $this->createMock(MagicMapper::class), schemaMapper: $schemaMapper, registerMapper: $registerMapper, saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $this->createMock(IUserSession::class), organisationService: $this->createMock(OrganisationService::class)),
			reporting: new BulkSaveReporting(logger: $this->createMock(LoggerInterface::class)),
			checks: new BulkRowChecks()
		);

		return (new ReflectionMethod(SaveObjects::class, 'loadAndValidateSchemaContext'))->invoke($handler, $register, $schema);
	}//end context()

	/**
	 * A schema with one inverse relation property.
	 *
	 * @param int $id The id.
	 *
	 * @return Schema
	 */
	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setProperties(['owner' => ['type' => 'object', 'inversedBy' => 'owned']]);
		return $schema;
	}//end schema()

	public function testEntitiesAreUsedAsGivenAndCachedUnderTheirIds(): void {
		$register = new Register();
		$register->setId(3);
		$schema = self::schema(4);

		$context = $this->context($register, $schema);

		$this->assertSame([], $this->loads);
		$this->assertSame(3, $context[0]);
		$this->assertSame($register, $context[1]);
		$this->assertSame(4, $context[2]);
		$this->assertSame($schema, $context[3]);
		$this->assertSame([4 => $schema], $context[4]);
		$this->assertSame([4], array_keys($context[5]));
		$this->assertSame(['owner' => ['inversedBy' => 'owned', 'writeBack' => false, 'isArray' => false]], $context[5][4]['inverseProperties']);
		$this->assertCount(6, $context);
		$this->assertSame([3 => $register], self::cache('registerCache'));
		$this->assertSame([4 => $schema], self::cache('schemaCache'));
	}//end testEntitiesAreUsedAsGivenAndCachedUnderTheirIds()

	public function testIdsAreLoadedAndReportedAsPassed(): void {
		$register = new Register();
		$register->setId(3);
		$schema = self::schema(4);

		$context = $this->context('3', '4', ['3' => $register], ['4' => $schema]);

		$this->assertSame(["register:'3'", "schema:'4'"], $this->loads);
		$this->assertSame('3', $context[0]);
		$this->assertSame($register, $context[1]);
		$this->assertSame('4', $context[2]);
		$this->assertSame($schema, $context[3]);
		$this->assertSame([4 => $schema], $context[4]);
		$this->assertSame([4], array_keys($context[5]));
	}//end testIdsAreLoadedAndReportedAsPassed()

	public function testARegisterEntityWithASchemaIdAndTheOtherWayRound(): void {
		$register = new Register();
		$register->setId(7);
		$schema = self::schema(8);

		$context = $this->context($register, 8, [], [8 => $schema]);
		$this->assertSame(['schema:8'], $this->loads);
		$this->assertSame([7, $register, 8, $schema], array_slice($context, 0, 4));

		$this->setUp();
		$context = $this->context(7, $schema, [7 => $register]);
		$this->assertSame(['register:7'], $this->loads);
		$this->assertSame([7, $register, 8, $schema], array_slice($context, 0, 4));
		$this->assertSame([7 => $register], self::cache('registerCache'));
		$this->assertSame([8 => $schema], self::cache('schemaCache'));
	}//end testARegisterEntityWithASchemaIdAndTheOtherWayRound()
}//end class
