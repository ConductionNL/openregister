<?php

/**
 * Golden tests for the AVG compliance checks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ProcessingLogMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCA\OpenRegister\Service\AvgComplianceService;
use OCA\OpenRegister\Service\UnclassifiedProcessing;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins which PII rows are reported unannotated, the unclassified-processing
 * count and every fail-soft branch, through the public checks.
 */
class AvgComplianceServiceGoldenTest extends TestCase {

	/**
	 * Lookups the mappers saw, as "kind id rbac multitenancy".
	 *
	 * @var array<int, string>
	 */
	private array $lookups = [];

	/**
	 * Only rows whose register and schema both lack an attribution are reported.
	 *
	 * @return void
	 */
	public function testOnlyRowsWithoutAnyAttributionAreReported(): void {
		$rows = [
			['register_id' => '', 'schema_id' => 's-legacy', 'pii_count' => 9],
			['register_id' => 'r-legacy', 'schema_id' => 's-plain', 'pii_count' => 1],
			['register_id' => 'r-plain', 'schema_id' => 's-dialect', 'pii_count' => 2],
			['register_id' => 'r-plain', 'schema_id' => 's-plain', 'pii_count' => '3'],
			['register_id' => 'r-missing', 'schema_id' => 's-missing', 'pii_count' => 4],
			['register_id' => 'r-plain', 'schema_id' => 's-empty-default'],
		];

		$service = $this->service(db: $this->db(rows: $rows), logMapper: null, vrwMapper: null, logger: $this->createMock(LoggerInterface::class));

		self::assertSame(
			[
				[
					'registerId' => 'r-plain',
					'schemaId' => 's-plain',
					'schemaTitle' => 'Plain schema',
					'piiCount' => 3,
					'registerHasAnnotation' => false,
					'schemaHasAnnotation' => false,
				],
				[
					'registerId' => 'r-missing',
					'schemaId' => 's-missing',
					'schemaTitle' => '',
					'piiCount' => 4,
					'registerHasAnnotation' => false,
					'schemaHasAnnotation' => false,
				],
				[
					'registerId' => 'r-plain',
					'schemaId' => 's-empty-default',
					'schemaTitle' => '',
					'piiCount' => 0,
					'registerHasAnnotation' => false,
					'schemaHasAnnotation' => false,
				],
			],
			$service->findUnannotatedSchemasWithPii()
		);
		self::assertNotEmpty($this->lookups);
		foreach ($this->lookups as $lookup) {
			self::assertStringEndsWith(' rbac=false mt=false', $lookup);
		}
	}//end testOnlyRowsWithoutAnyAttributionAreReported()

	/**
	 * A failing aggregation is logged and reports nothing; no rows reports nothing.
	 *
	 * @return void
	 */
	public function testFailingOrEmptyAggregationReportsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[AVG compliance] Failed to aggregate PII by schema', ['error' => 'db gone']);
		$failing = $this->createMock(IDBConnection::class);
		$failing->method('getQueryBuilder')->willThrowException(new RuntimeException('db gone'));

		self::assertSame([], $this->service(db: $failing, logMapper: null, vrwMapper: null, logger: $logger)->findUnannotatedSchemasWithPii());
		self::assertSame([], $this->service(db: $this->db(rows: []), logMapper: null, vrwMapper: null, logger: $this->createMock(LoggerInterface::class))->findUnannotatedSchemasWithPii());
	}//end testFailingOrEmptyAggregationReportsNothing()

	/**
	 * The unclassified count: 0 without the mappers or the fallback, the fallback's count otherwise, 0 on failure.
	 *
	 * @return void
	 */
	public function testUnclassifiedProcessingCount(): void {
		$quiet = $this->createMock(LoggerInterface::class);
		$quiet->expects($this->never())->method('warning');
		$db = $this->db(rows: []);

		$fallback = new Verwerkingsactiviteit();
		$fallback->setUuid('fb-uuid');
		$vrw = $this->createMock(VerwerkingsactiviteitMapperInterface::class);
		$vrw->method('findByCode')->with(AvgComplianceService::FALLBACK_CODE)->willReturn($fallback);
		$log = $this->createMock(ProcessingLogMapperInterface::class);
		$log->method('countByActivity')->willReturn(['fb-uuid' => 7, 'other' => 2]);

		self::assertSame(0, $this->service(db: $db, logMapper: null, vrwMapper: $vrw, logger: $quiet)->countUnclassifiedProcessing());
		self::assertSame(0, $this->service(db: $db, logMapper: $log, vrwMapper: null, logger: $quiet)->countUnclassifiedProcessing());
		self::assertSame(7, $this->service(db: $db, logMapper: $log, vrwMapper: $vrw, logger: $quiet)->countUnclassifiedProcessing());

		$noFallback = $this->createMock(VerwerkingsactiviteitMapperInterface::class);
		$noFallback->method('findByCode')->willReturn(null);
		self::assertSame(0, $this->service(db: $db, logMapper: $log, vrwMapper: $noFallback, logger: $quiet)->countUnclassifiedProcessing());

		$noEntries = $this->createMock(ProcessingLogMapperInterface::class);
		$noEntries->method('countByActivity')->willReturn(['other' => 2]);
		self::assertSame(0, $this->service(db: $db, logMapper: $noEntries, vrwMapper: $vrw, logger: $quiet)->countUnclassifiedProcessing());

		$broken = $this->createMock(ProcessingLogMapperInterface::class);
		$broken->method('countByActivity')->willThrowException(new RuntimeException('log gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[AVG compliance] Failed to count unclassified processing', ['error' => 'log gone']);
		self::assertSame(0, $this->service(db: $db, logMapper: $broken, vrwMapper: $vrw, logger: $logger)->countUnclassifiedProcessing());
	}//end testUnclassifiedProcessingCount()

	/**
	 * runAllChecks totals both checks.
	 *
	 * @return void
	 */
	public function testRunAllChecksTotalsBothChecks(): void {
		$fallback = new Verwerkingsactiviteit();
		$fallback->setUuid('fb-uuid');
		$vrw = $this->createMock(VerwerkingsactiviteitMapperInterface::class);
		$vrw->method('findByCode')->willReturn($fallback);
		$log = $this->createMock(ProcessingLogMapperInterface::class);
		$log->method('countByActivity')->willReturn(['fb-uuid' => 5]);

		$rows   = [['register_id' => 'r-plain', 'schema_id' => 's-plain', 'pii_count' => 1]];
		$result = $this->service(db: $this->db(rows: $rows), logMapper: $log, vrwMapper: $vrw, logger: $this->createMock(LoggerInterface::class))->runAllChecks();

		self::assertSame(['unannotatedSchemasWithPii' => 1, 'unclassifiedProcessing' => 5], $result['totals']);
		self::assertCount(1, $result['issues']['unannotatedSchemasWithPii']);
		self::assertIsString($result['generated']);
	}//end testRunAllChecksTotalsBothChecks()

	/**
	 * Build the service under test with the fixed register and schema fixtures.
	 *
	 * @param IDBConnection                              $db        The connection.
	 * @param ProcessingLogMapperInterface|null          $logMapper The read-log mapper.
	 * @param VerwerkingsactiviteitMapperInterface|null  $vrwMapper The activity mapper.
	 * @param LoggerInterface                            $logger    The logger.
	 *
	 * @return AvgComplianceService
	 */
	private function service(
		IDBConnection $db,
		?ProcessingLogMapperInterface $logMapper,
		?VerwerkingsactiviteitMapperInterface $vrwMapper,
		LoggerInterface $logger
	): AvgComplianceService {
		return new AvgComplianceService(
			$db,
			new SchemaCatalog($this->registers(), $this->schemas(), $this->createMock(MagicMapperInterface::class)),
			$logger,
			new UnclassifiedProcessing($logMapper, $vrwMapper)
		);
	}//end service()

	/**
	 * Registers: r-legacy carries the legacy key, r-plain nothing, r-missing does not exist.
	 *
	 * @return RegisterMapperInterface
	 */
	private function registers(): RegisterMapperInterface {
		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function ($id, $rbac, $multitenancy) {
				$this->lookups[] = 'register ' . $id . ' rbac=' . var_export($rbac, true) . ' mt=' . var_export($multitenancy, true);
				if ($id === 'r-missing') {
					throw new DoesNotExistException('gone');
				}

				$register = new Register();
				$register->setConfiguration($id === 'r-legacy' ? [AvgComplianceService::ANNOTATION_KEY => 'act-1'] : []);
				return $register;
			}
		);

		return $mapper;
	}//end registers()

	/**
	 * Schemas: s-dialect has an attribution default, s-empty-default an empty one, s-missing does not exist.
	 *
	 * @return SchemaMapperInterface
	 */
	private function schemas(): SchemaMapperInterface {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function ($id, $extend, $rbac, $multitenancy) {
				$this->lookups[] = 'schema ' . $id . ' rbac=' . var_export($rbac, true) . ' mt=' . var_export($multitenancy, true);
				if ($id === 's-missing') {
					throw new DoesNotExistException('gone');
				}

				$schema = new Schema();
				$config = [];
				if ($id === 's-dialect') {
					$config = [AvgComplianceService::DIALECT_KEY => ['attribution' => ['default' => 'act-2']]];
				}

				if ($id === 's-empty-default') {
					$config = [AvgComplianceService::DIALECT_KEY => ['attribution' => ['default' => '']]];
					$schema->setTitle(null);
				}

				if ($id === 's-plain') {
					$schema->setTitle('Plain schema');
				}

				$schema->setConfiguration($config);
				return $schema;
			}
		);

		return $mapper;
	}//end schemas()

	/**
	 * A connection whose aggregation query returns the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The aggregated rows.
	 *
	 * @return IDBConnection
	 */
	private function db(array $rows): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectAlias', 'from', 'innerJoin', 'where', 'groupBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('func')->willReturn($func);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end db()
}//end class
