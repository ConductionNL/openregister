<?php

/**
 * What counts as a reference to a schema, for slugs that share a prefix and schemas without a slug.
 *
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);


namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapper\SchemaReferenceSearch;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper\SchemaReferenceSearch
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Db\ColumnFiltersTrait
 */
class SchemaReferenceSearchBoundaryTest extends TestCase {
	/**
	 * A slug that is the start of another slug is not a reference to that other schema.
	 *
	 * @return void
	 */
	public function testASlugIsNotMatchedAsThePrefixOfALongerSlug(): void {
		$search = new SchemaReferenceSearch(target: ['id' => '12', 'uuid' => 'u', 'slug' => 'zaak']);

		$this->assertFalse($search->foundIn(properties: ['a' => ['$ref' => '#/components/schemas/zaaktype']]));
		$this->assertFalse($search->foundIn(properties: ['a' => ['$ref' => 'schemas/zaak-type']]));
		$this->assertFalse($search->foundIn(properties: ['a' => ['$ref' => 'schemas/zaak_type']]));
		$this->assertTrue($search->foundIn(properties: ['a' => ['$ref' => '#/components/schemas/zaak']]));
		$this->assertTrue($search->foundIn(properties: ['a' => ['$ref' => 'schemas/zaak/properties/x']]));
		$this->assertTrue($search->foundIn(properties: ['a' => ['$ref' => 'schemas/zaak.json']]));
	}//end testASlugIsNotMatchedAsThePrefixOfALongerSlug()

	/**
	 * An empty `$ref` names nothing, also for a target that has no slug or uuid.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceNamesNoSchema(): void {
		$search = new SchemaReferenceSearch(target: ['id' => '12', 'uuid' => '', 'slug' => '']);

		$this->assertFalse($search->foundIn(properties: ['a' => ['$ref' => '']]));
	}//end testAnEmptyReferenceNamesNoSchema()

	/**
	 * A stored schema without a slug or uuid raised a TypeError on its related list.
	 *
	 * @return void
	 */
	public function testRelatedSchemasOfATargetWithoutSlugOrUuid(): void {
		$target = new Schema();
		$target->setId(12);
		$candidate = new Schema();
		$candidate->setId(13);
		$candidate->setProperties(['a' => ['$ref' => '12']]);

		$mapper = $this->getMockBuilder(SchemaMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
					new TenancyServices(
						$this->createMock(OrganisationMapper::class),
						$this->createMock(IUserSession::class),
						$this->createMock(IGroupManager::class)
					),
					$this->createMock(IAppConfig::class),
					new SchemaMapperServices(
						$this->createMock(IEventDispatcher::class),
						$this->createMock(PropertyValidatorHandler::class),
						$this->createMock(LoggerInterface::class)
					),
				]
			)
			->onlyMethods(['findAll'])
			->getMock();
		$mapper->method('findAll')->willReturn([$target, $candidate]);

		$this->assertSame([$candidate], $mapper->getRelated($target));
	}//end testRelatedSchemasOfATargetWithoutSlugOrUuid()
}//end class
