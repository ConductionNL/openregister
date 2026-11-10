<?php

/**
 * A bulk save starts clean: no folder verdict and no pending schema from before it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\Object\BulkSaveOptions;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use OCA\OpenRegister\Service\Object\BulkBatchDefaults;

/**
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\BulkCacheInvalidator
 * @uses   \OCA\OpenRegister\Service\Object\SavedScopeIds
 */
class ObjectServiceBulkSaveContextTest extends TestCase {

	/**
	 * The schema the bulk save ran under.
	 *
	 * @var Schema|null
	 */
	private ?Schema $savedUnder = null;

	/**
	 * A real ObjectService holding a ref an earlier caller left behind.
	 *
	 * @param FileService $files  The file service.
	 * @param Schema|null $schema The schema the service is anchored to.
	 *
	 * @return ObjectService
	 */
	private function service(FileService $files, ?Schema $schema = null): ObjectService {
		$bulk = $this->createMock(SaveObjects::class);
		$bulk->method('saveObjects')->willReturnCallback(
			function (array $objects, BulkSaveOptions $options): array {
				$this->savedUnder = $options->defaults->schema;
				return ['statistics' => []];
			}
		);

		$service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		$values  = [
			'saveObjectsHandler' => $bulk,
			'fileService'        => $files,
			'currentSchema'      => $schema,
			'cacheHandler'       => $this->createMock(CacheHandlerInterface::class),
			'logger'             => $this->createMock(LoggerInterface::class),
			'currentSchemaRef'   => 'a-schema-of-an-earlier-caller',
		];
		foreach ($values as $name => $value) {
			(new ReflectionProperty(ObjectService::class, $name))->setValue($service, $value);
		}

		return $service;
	}//end service()

	public function testAPendingSchemaRefDoesNotCostTheBulkSaveItsSchema(): void {
		$schema   = new Schema();
		$register = new Register();
		$register->setId(1);

		$this->service($this->createMock(FileService::class), $schema)->saveObjects(objects: [], options: new BulkSaveOptions(defaults: new BulkBatchDefaults(register: $register)));

		$this->assertSame($schema, $this->savedUnder);
	}//end testAPendingSchemaRefDoesNotCostTheBulkSaveItsSchema()

	public function testTheFolderVerdictsOfEarlierWritesAreDropped(): void {
		$files = $this->createMock(FileService::class);
		$files->expects($this->once())->method('resetFolderAccessRevalidationCache');

		$this->service($files)->saveObjects(objects: []);
	}//end testTheFolderVerdictsOfEarlierWritesAreDropped()
}//end class
