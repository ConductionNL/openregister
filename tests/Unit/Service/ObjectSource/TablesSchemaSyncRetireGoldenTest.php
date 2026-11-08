<?php

/**
 * Access golden test for TablesSchemaSyncService retirement and the SQLSTATE
 * mapping of DbalWriteException: which mirrors are retired and which are kept,
 * that a refused delete keeps its schema linked, that the delete is never
 * forced, and the status and message per SQLSTATE.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins retirement outcomes and the SQLSTATE mapping before their units are split.
 */
class TablesSchemaSyncRetireGoldenTest extends TestCase {

	/**
	 * The schemas the service tried to delete, with the arguments it passed.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $deletes = [];

	/**
	 * The schema lists the register was saved with.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saved = [];

	/**
	 * A managed (or hand-authored) Tables mirror schema.
	 *
	 * @param int  $id      The schema id.
	 * @param int  $tableId The mirrored table.
	 * @param bool $managed Whether the sync owns it.
	 *
	 * @return Schema
	 */
	private function schema(int $id, int $tableId, bool $managed = true): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('mirror-' . $id);
		$schema->setConfiguration(
			[
				'x-openregister-object-source' => [
					'provider' => 'tables',
					'readOnly' => true,
					'config' => ['tableId' => $tableId, 'managed' => $managed],
				],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * A service over a register linking the given references.
	 *
	 * @param array<int, mixed>         $refs      The register's schema references.
	 * @param array<string, Schema>     $schemas   Schemas by reference.
	 * @param array<int, int>           $refuse    Schema ids whose delete is refused.
	 *
	 * @return TablesSchemaSyncService
	 */
	private function service(array $refs, array $schemas, array $refuse = []): TablesSchemaSyncService {
		$register = new Register();
		$register->setId(30);
		$register->setSlug('tables');
		$register->setSchemas($refs);

		$this->deletes = [];
		$this->saved   = [];

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$registerMapper->method('update')->willReturnCallback(
			function (Register $saved): Register {
				$this->saved[] = $saved->getSchemas();
				return $saved;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($ref) use ($schemas): Schema {
				if (isset($schemas[(string)$ref]) === true) {
					return $schemas[(string)$ref];
				}

				throw new DoesNotExistException('none');
			}
		);
		$schemaMapper->method('delete')->willReturnCallback(
			function (Schema $schema) use ($refuse): Schema {
				$this->deletes[] = func_get_args();
				if (in_array($schema->getId(), $refuse, true) === true) {
					throw new RuntimeException('the magic table holds rows');
				}

				return $schema;
			}
		);

		return new TablesSchemaSyncService(
			$registerMapper,
			$schemaMapper,
			new TablesColumnMapper(new TablesUuidDeriver(), new NullLogger()),
			new NullLogger()
		);
	}//end service()

	public function testReconcileRetiresUnseenMirrorsAndKeepsRefusedOnesLinked(): void {
		$kept     = $this->schema(id: 200, tableId: 1);
		$gone     = $this->schema(id: 201, tableId: 2);
		$refused  = $this->schema(id: 202, tableId: 3);
		$authored = $this->schema(id: 203, tableId: 4, managed: false);
		$service  = $this->service(
			refs: [200, '201', 'mirror-202', 203, 999],
			schemas: ['200' => $kept, '201' => $gone, 'mirror-202' => $refused, '203' => $authored],
			refuse: [202]
		);

		$retired = (new \ReflectionMethod(TablesSchemaSyncService::class, 'retireMissing'))->invoke(
			$service,
			$this->registerFor(service: $service),
			[1]
		);

		$this->assertSame(1, $retired);
		// Never forced: the second argument is the default `false`.
		$this->assertSame([[$gone, false], [$refused, false]], $this->deletes);
		$this->assertSame([[200, 'mirror-202', 203, 999]], $this->saved);
	}//end testReconcileRetiresUnseenMirrorsAndKeepsRefusedOnesLinked()

	public function testOnlyTheNamedTableIsRetired(): void {
		$one     = $this->schema(id: 200, tableId: 1);
		$two     = $this->schema(id: 201, tableId: 2);
		$service = $this->service(refs: [200, 201], schemas: ['200' => $one, '201' => $two]);

		$this->assertTrue($service->retireByTableId(tableId: 1));
		$this->assertSame([[$one, false]], $this->deletes);
		$this->assertSame([[201]], $this->saved);

		$this->assertFalse($service->retireByTableId(tableId: 7));
		$this->assertSame([[$one, false]], $this->deletes);
		$this->assertSame([[201]], $this->saved);
	}//end testOnlyTheNamedTableIsRetired()

	public function testARefusedOnlyDeleteSavesNothing(): void {
		$one     = $this->schema(id: 200, tableId: 1);
		$service = $this->service(refs: [200], schemas: ['200' => $one], refuse: [200]);

		$this->assertFalse($service->retireByTableId(tableId: 1));
		$this->assertSame([[$one, false]], $this->deletes);
		$this->assertSame([], $this->saved);
	}//end testARefusedOnlyDeleteSavesNothing()

	public function testSqlStateMapping(): void {
		$cases = [
			'23505' => [409, 'A unique constraint on the external table was violated.'],
			'23503' => [409, 'A foreign-key constraint on the external table was violated.'],
			'23502' => [422, 'A required (not-null) column on the external table was not provided.'],
			'23514' => [422, 'A check constraint on the external table was violated.'],
			'22001' => [422, 'A value did not match the external column type.'],
			'22'    => [422, 'A value did not match the external column type.'],
			'23000' => [502, 'The external database rejected the write.'],
			''      => [502, 'The external database rejected the write.'],
		];
		$previous = new RuntimeException('driver');
		foreach ($cases as $state => $expected) {
			$exception = DbalWriteException::fromSqlState(sqlState: (string)$state, previous: $previous);
			$this->assertSame($expected, [$exception->getStatusCode(), $exception->getMessage()], 'state ' . $state);
			$this->assertSame($previous, $exception->getPrevious());
		}

		$this->assertNull(DbalWriteException::fromSqlState(sqlState: '23505')->getPrevious());
	}//end testSqlStateMapping()

	/**
	 * The register the service reads.
	 *
	 * @param TablesSchemaSyncService $service The service.
	 *
	 * @return Register
	 */
	private function registerFor(TablesSchemaSyncService $service): Register {
		$property = new \ReflectionProperty(TablesSchemaSyncService::class, 'registerMapper');
		return $property->getValue($service)->find(30);
	}//end registerFor()
}//end class
