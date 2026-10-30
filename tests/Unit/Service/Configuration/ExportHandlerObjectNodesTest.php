<?php

/**
 * A schema property whose `items` or `objectConfiguration` is an object exports.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A decoded schema can hold `items` and `objectConfiguration` as objects
 * rather than arrays. The export writes their schema and register ids as
 * slugs, the same as for arrays, instead of failing the whole export.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ExportHandler
 * @covers \OCA\OpenRegister\Service\Configuration\ExportSlugMaps
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Authorization\RbacGroupCollector
 */
class ExportHandlerObjectNodesTest extends TestCase {

	/**
	 * Export one register whose schema has the given properties.
	 *
	 * @param array<string, mixed> $properties The schema properties.
	 *
	 * @return array<string, mixed> The exported properties.
	 */
	private function exportedProperties(array $properties): array {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg-one');

		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('person');
		$schema->setProperties($properties);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getSchemasByRegisterId')->willReturn([$schema]);
		$registerMapper->method('getIdToSlugMap')->willReturn(['1' => 'reg-one', '2' => 'reg-two']);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('getIdToSlugMap')->willReturn(['11' => 'item', '12' => 'person']);

		$handler = new ExportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $registerMapper,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $this->createMock(MappingMapper::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		return $handler->exportConfig($register)['components']['schemas']['person']['properties'];
	}//end exportedProperties()

	/**
	 * Object `items` get their `$ref`, `objectConfiguration` and legacy register as slugs.
	 *
	 * @return void
	 *
	 */
	public function testObjectItemsExportWithSlugs(): void {
		$items = new \stdClass();
		$items->{'$ref'} = '/schemas/11';
		$items->register = 'https://host/api/registers/2';
		$items->objectConfiguration = (object)['register' => 1, 'schema' => '12'];

		$exported = $this->exportedProperties(properties: ['list' => ['type' => 'array', 'items' => $items]]);

		self::assertSame(
			['$ref' => 'item', 'register' => 'reg-two', 'objectConfiguration' => ['register' => 'reg-one', 'schema' => 'person']],
			$exported['list']['items']
		);
	}//end testObjectItemsExportWithSlugs()

	/**
	 * An object `objectConfiguration` gets its register and schema ids as slugs.
	 *
	 * @return void
	 *
	 */
	public function testObjectConfigurationExportsWithSlugs(): void {
		$exported = $this->exportedProperties(
			properties: ['owner' => ['type' => 'object', 'objectConfiguration' => (object)['register' => 2, 'schema' => 11]]]
		);

		self::assertSame(['register' => 'reg-two', 'schema' => 'item'], $exported['owner']['objectConfiguration']);
	}//end testObjectConfigurationExportsWithSlugs()
}//end class
