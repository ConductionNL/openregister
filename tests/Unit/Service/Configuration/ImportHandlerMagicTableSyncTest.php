<?php

/**
 * Unit tests for magic-table reconciliation of imported schemas in ImportHandler.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks the behaviour of ensureMagicTablesForImportedSchemas().
 *
 * Regression cover for #2082: an import updated a schema definition but never
 * reconciled the physical magic table, so a property added to an EXISTING
 * schema in an EXISTING register (with no seed data) had no column. Reads that
 * filtered on it — e.g. a schema RBAC rule matching on the new property — then
 * failed with a raw "column does not exist" SQL error, and a FORCED re-import
 * did not repair it either. Observed live on softwarecatalog, where 11 columns
 * across two schemas were missing.
 */
class ImportHandlerMagicTableSyncTest extends TestCase {

	private ImportHandler $handler;

	private SchemaMapper|MockObject $schemaMapper;

	private RegisterMapper|MockObject $registerMapper;

	private MagicMapper|MockObject $objectEntityMapper;

	private ConfigurationMapper|MockObject $configurationMapper;

	private MappingMapper|MockObject $mappingMapper;

	private Client|MockObject $client;

	private IAppConfig|MockObject $appConfig;

	private LoggerInterface|MockObject $logger;

	private UploadHandler|MockObject $uploadHandler;

	private ObjectService|MockObject $objectService;

	private MagicMapper|MockObject $magicMapper;

	/**
	 * Build an ImportHandler with fully mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->configurationMapper = $this->createMock(ConfigurationMapper::class);
		$this->mappingMapper = $this->createMock(MappingMapper::class);
		$this->client = $this->createMock(Client::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->uploadHandler = $this->createMock(UploadHandler::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);

		$this->handler = new ImportHandler(
			schemaMapper:        $this->schemaMapper,
			registerMapper:      $this->registerMapper,
			objectEntityMapper:  $this->objectEntityMapper,
			configurationMapper: $this->configurationMapper,
			mappingMapper:       $this->mappingMapper,
			client:              $this->client,
			appConfig:           $this->appConfig,
			logger:              $this->logger,
			appDataPath:         '/tmp',
			uploadHandler:       $this->uploadHandler,
			objectService:       $this->objectService
		);

	}//end setUp()

	/**
	 * Invoke the private reconciliation method under test.
	 *
	 * @param array $schemas Imported schema entities.
	 *
	 * @return void
	 */
	private function invokeSync(array $schemas): void {
		$reflection = new \ReflectionMethod(ImportHandler::class, 'ensureMagicTablesForImportedSchemas');
		$reflection->setAccessible(true);
		$reflection->invoke($this->handler, $schemas);

	}//end invokeSync()

	/**
	 * Build a Schema entity with an id and slug.
	 *
	 * @param int $id Schema id.
	 * @param string $slug Schema slug.
	 *
	 * @return Schema
	 */
	private function makeSchema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle($slug);

		return $schema;
	}//end makeSchema()

	/**
	 * Every imported schema is reconciled against every register that holds it.
	 *
	 * This is the case that regressed: without it, a property added to an
	 * existing schema never got a physical column.
	 *
	 * @return void
	 */
	public function testReconcilesEveryImportedSchemaInEveryOwningRegister(): void {
		$schema = $this->makeSchema(id: 43, slug: 'beoordeeling');

		$registerA = new Register();
		$registerA->setId(11);
		$registerB = new Register();
		$registerB->setId(12);

		$this->registerMapper->method('getAllRegisterIdsWithSchema')
			->with(schemaId: 43)
			->willReturn([11, 12]);

		$this->registerMapper->method('find')
			->willReturnCallback(
				static function (string|int $id) use ($registerA, $registerB): Register {
					if ((int)$id === 11) {
						return $registerA;
					}

					return $registerB;
				}
			);

		$seen = [];
		$this->magicMapper->expects($this->exactly(2))
			->method('ensureTableForRegisterSchema')
			->willReturnCallback(
				static function (Register $register, Schema $schema) use (&$seen): bool {
					$seen[] = $register->getId() . ':' . $schema->getId();
					return true;
				}
			);

		$this->handler->setMagicMapper($this->magicMapper);
		$this->invokeSync([$schema]);

		$this->assertSame(['11:43', '12:43'], $seen);

	}//end testReconcilesEveryImportedSchemaInEveryOwningRegister()

	/**
	 * A failure on one register must not abort the remaining reconciliations.
	 *
	 * An import that already succeeded must never be lost because one physical
	 * table could not be reconciled.
	 *
	 * @return void
	 */
	public function testOneFailingRegisterDoesNotAbortTheRest(): void {
		$schema = $this->makeSchema(id: 43, slug: 'beoordeeling');

		$register = new Register();
		$register->setId(11);

		$this->registerMapper->method('getAllRegisterIdsWithSchema')->willReturn([11, 12]);
		$this->registerMapper->method('find')->willReturn($register);

		$calls = 0;
		$this->magicMapper->expects($this->exactly(2))
			->method('ensureTableForRegisterSchema')
			->willReturnCallback(
				static function () use (&$calls): bool {
					$calls++;
					if ($calls === 1) {
						throw new \Exception('table locked');
					}

					return true;
				}
			);

		$this->handler->setMagicMapper($this->magicMapper);
		$this->invokeSync([$schema]);

		$this->assertSame(2, $calls);

	}//end testOneFailingRegisterDoesNotAbortTheRest()

	/**
	 * Without a MagicMapper the reconciliation is a silent no-op.
	 *
	 * @return void
	 */
	public function testNoMagicMapperIsANoOp(): void {
		$this->registerMapper->expects($this->never())->method('getAllRegisterIdsWithSchema');

		$this->invokeSync([$this->makeSchema(id: 43, slug: 'beoordeeling')]);

	}//end testNoMagicMapperIsANoOp()

	/**
	 * Non-Schema entries and id-less schemas are skipped without throwing.
	 *
	 * @return void
	 */
	public function testSkipsNonSchemaEntriesAndSchemasWithoutId(): void {
		$this->registerMapper->expects($this->never())->method('getAllRegisterIdsWithSchema');
		$this->magicMapper->expects($this->never())->method('ensureTableForRegisterSchema');

		$this->handler->setMagicMapper($this->magicMapper);
		$this->invokeSync(['not-a-schema', new Schema()]);

	}//end testSkipsNonSchemaEntriesAndSchemasWithoutId()

	/**
	 * A register-resolution failure is logged and skipped, not fatal.
	 *
	 * @return void
	 */
	public function testRegisterResolutionFailureIsNotFatal(): void {
		$this->registerMapper->method('getAllRegisterIdsWithSchema')
			->willThrowException(new \Exception('db down'));

		$this->magicMapper->expects($this->never())->method('ensureTableForRegisterSchema');

		$this->handler->setMagicMapper($this->magicMapper);
		$this->invokeSync([$this->makeSchema(id: 43, slug: 'beoordeeling')]);

	}//end testRegisterResolutionFailureIsNotFatal()

}//end class
