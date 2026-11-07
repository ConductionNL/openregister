<?php

/**
 * Characterization test for the serialized finders and folder provisioning of
 * {@see \OCA\OpenRegister\Service\RegisterService}.
 *
 * Written against the code before it was split into small units: pins which
 * mapper calls (and which `_multitenancy` flags) the serialized finders make,
 * the stats handed to the serializer, and the three folder outcomes of
 * createFromArray (created, not created, failed).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IPreparedStatement;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * RegisterServiceSerializedGoldenTest.
 */
class RegisterServiceSerializedGoldenTest extends TestCase {

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private IDBConnection&MockObject $db;

	private FileService&MockObject $fileService;

	private OrganisationService&MockObject $organisationService;

	private LoggerInterface&MockObject $logger;

	private RegisterService $service;

	protected function setUp(): void {
		$this->registerMapper      = $this->createMock(RegisterMapper::class);
		$this->schemaMapper        = $this->createMock(SchemaMapper::class);
		$this->db                  = $this->createMock(IDBConnection::class);
		$this->fileService         = $this->createMock(FileService::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->logger              = $this->createMock(LoggerInterface::class);

		$this->service = new RegisterService(
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			db: $this->db,
			fileService: $this->fileService,
			organisationService: $this->organisationService,
			logger: $this->logger,
			registerSerializer: new RegisterSerializer($this->schemaMapper, $this->logger)
		);
	}//end setUp()

	public function testFindSerializedWithoutStatsTouchesNoTables(): void {
		$this->registerMapper->expects($this->once())->method('find')
			->with(7, true, false)
			->willReturn($this->register(id: 7, schemas: [20]));
		$this->schemaMapper->method('find')->willReturnCallback(fn ($id): Schema => $this->schema(id: (int)$id));
		$this->db->expects($this->never())->method('prepare');

		$out = $this->service->findSerialized(7, ['schemas'], false);

		$this->assertSame(20, $out['schemas'][0]['id']);
		$this->assertArrayNotHasKey('stats', $out['schemas'][0]);
	}//end testFindSerializedWithoutStatsTouchesNoTables()

	public function testFindSerializedWithStatsCountsLiveTablesAndSkipsOrphans(): void {
		$this->registerMapper->method('find')->willReturn($this->register(id: 7, schemas: [20, 21, 99]));
		$this->schemaMapper->method('find')->willReturnCallback(
			function ($id, $extend = [], $rbac = true, $multitenancy = true): Schema {
				$this->assertSame([], $extend);
				$this->assertFalse($multitenancy);
				if ((int)$id === 99) {
					throw new DoesNotExistException('orphan');
				}

				return $this->schema(id: (int)$id);
			}
		);
		$this->catalog(tables: ['oc_openregister_table_7_20'], countRows: [['schema_id' => '20', 'total' => 5, 'deleted' => 1, 'invalid' => 0, 'locked' => 0, 'size' => 0]]);

		$out = $this->service->findSerialized(7, ['schemas', '@self.stats']);

		$this->assertSame(['objects' => ['total' => 5]], $out['schemas'][0]['stats']);
		$this->assertSame(['objects' => ['total' => 0]], $out['schemas'][1]['stats']);
		$this->assertSame(99, $out['schemas'][2]);
	}//end testFindSerializedWithStatsCountsLiveTablesAndSkipsOrphans()

	public function testFindAllSerializedPassesTheQueryThroughAndCountsPerRegister(): void {
		$this->registerMapper->expects($this->once())->method('findAll')
			->with(10, 5, ['a' => 1], ['c'], ['p' => 2], true, false)
			->willReturn([$this->register(id: 7, schemas: [20]), $this->register(id: 8, schemas: [20])]);
		$this->schemaMapper->method('find')->willReturnCallback(fn ($id): Schema => $this->schema(id: (int)$id));
		$this->catalog(
			tables: ['oc_openregister_table_7_20', 'oc_openregister_table_8_20'],
			countRows: [['schema_id' => '20', 'total' => 3, 'deleted' => 0, 'invalid' => 0, 'locked' => 0, 'size' => 0]],
			countRuns: 2
		);

		$out = $this->service->findAllSerialized(10, 5, ['a' => 1], ['c'], ['p' => 2], ['schemas', '@self.stats'], false);

		$this->assertCount(2, $out);
		$this->assertSame(['objects' => ['total' => 3]], $out[0]['schemas'][0]['stats']);
		$this->assertSame(['objects' => ['total' => 3]], $out[1]['schemas'][0]['stats']);
	}//end testFindAllSerializedPassesTheQueryThroughAndCountsPerRegister()

	public function testFindAllSerializedWithoutStatsCountsNothing(): void {
		$this->registerMapper->method('findAll')->willReturn([$this->register(id: 7, schemas: [])]);
		$this->db->expects($this->never())->method('prepare');

		$out = $this->service->findAllSerialized(_extend: ['@self.stats']);

		$this->assertSame(7, $out[0]['id']);
	}//end testFindAllSerializedWithoutStatsCountsNothing()

	public function testCreateFromArrayRecordsTheNewFolderId(): void {
		$register = $this->register(id: 7, schemas: []);
		$register->setOrganisation('org-a');
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(321);
		$this->registerMapper->method('createFromArray')->willReturn($register);
		$this->fileService->expects($this->once())->method('createEntityFolder')->willReturn($folder);
		$this->registerMapper->expects($this->once())->method('update')->with($register)->willReturn($register);
		$this->logger->expects($this->exactly(2))->method('info');

		$this->assertSame($register, $this->service->createFromArray(['title' => 'x']));
		$this->assertSame('321', $register->getFolder());
	}//end testCreateFromArrayRecordsTheNewFolderId()

	public function testCreateFromArrayWarnsWhenNoFolderCameBack(): void {
		$register = $this->register(id: 7, schemas: []);
		$register->setOrganisation('org-a');
		$this->registerMapper->method('createFromArray')->willReturn($register);
		$this->fileService->method('createEntityFolder')->willReturn(null);
		$this->registerMapper->expects($this->never())->method('update');
		$this->logger->expects($this->once())->method('warning');
		$this->logger->expects($this->once())->method('info');

		$this->assertSame($register, $this->service->createFromArray(['title' => 'x']));
	}//end testCreateFromArrayWarnsWhenNoFolderCameBack()

	public function testCreateFromArraySurvivesAFolderFailure(): void {
		$register = $this->register(id: 7, schemas: []);
		$register->setOrganisation('org-a');
		$this->registerMapper->method('createFromArray')->willReturn($register);
		$this->fileService->method('createEntityFolder')->willThrowException(new Exception('disk full'));
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('disk full'));

		$this->assertSame($register, $this->service->createFromArray(['title' => 'x']));
	}//end testCreateFromArraySurvivesAFolderFailure()

	/**
	 * `folder` is a ?string column, so the legacy-string-path branch is taken
	 * for every stored value, a stored id included. Pinned as found.
	 */
	public function testCreateFromArrayAsksForAFolderEvenWithOneStored(): void {
		$register = $this->register(id: 7, schemas: []);
		$register->setOrganisation('org-a');
		$register->setFolder('55');
		$this->registerMapper->method('createFromArray')->willReturn($register);
		$this->fileService->expects($this->once())->method('createEntityFolder')->willReturn(null);

		$this->assertSame($register, $this->service->createFromArray(['title' => 'x']));
	}//end testCreateFromArrayAsksForAFolderEvenWithOneStored()

	/**
	 * Wire the catalog read and the count query(ies).
	 *
	 * @param array<int, string>               $tables    Catalog table names.
	 * @param array<int, array<string, mixed>> $countRows Rows each count query returns.
	 * @param int                              $countRuns How many count queries run.
	 *
	 * @return void
	 */
	private function catalog(array $tables, array $countRows, int $countRuns = 1): void {
		$catalog = $this->createMock(IPreparedStatement::class);
		$catalogRows = array_map(static fn (string $t): array => ['table_name' => $t], $tables);
		$catalog->method('fetch')->willReturnOnConsecutiveCalls(...[...$catalogRows, false]);

		$counts = [];
		for ($i = 0; $i < $countRuns; $i++) {
			$stmt = $this->createMock(IPreparedStatement::class);
			$stmt->method('fetch')->willReturnOnConsecutiveCalls(...[...$countRows, false]);
			$counts[] = $stmt;
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getTableName')->willReturnCallback(static fn (string $t): string => 'oc_' . $t);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->db->expects($this->exactly(1 + $countRuns))->method('prepare')
			->willReturnOnConsecutiveCalls($catalog, ...$counts);
	}//end catalog()

	/**
	 * A register with the given schema ids.
	 *
	 * @param int        $id      The register id.
	 * @param array<int> $schemas Its schema ids.
	 *
	 * @return Register
	 */
	private function register(int $id, array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSchemas($schemas);

		return $register;
	}//end register()

	/**
	 * A schema with the given id.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('s' . $id);

		return $schema;
	}//end schema()
}//end class
