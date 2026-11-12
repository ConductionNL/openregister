<?php

/**
 * OpenRegister - golden of the rows SchemaCacheHandler writes and the debug lines it logs.
 *
 * Pins, for each cache write, the update-then-insert sequence and the exact
 * values written (schema id, cache key, JSON payload, the expiry relative to
 * the write time, a null expiry for a zero TTL, the eight-hour TTL ceiling),
 * the removal of an expired row, and the schema id carried by every debug
 * line. Times are compared to each other, never to the clock.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use DateTime;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\SchemaCacheHandler
 */
final class SchemaCacheHandlerRowsGoldenTest extends TestCase {

	/**
	 * Every statement the handler built, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $statements = [];

	/**
	 * What executeStatement answers, per statement kind.
	 *
	 * @var array<string, int>
	 */
	private array $affected = ['update' => 0, 'insert' => 1, 'delete' => 1];

	/**
	 * The row a select fetches.
	 *
	 * @var mixed
	 */
	private mixed $fetched = false;

	/**
	 * Debug lines logged: message and context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $debug = [];

	/**
	 * The handler under test.
	 *
	 * @var SchemaCacheHandler
	 */
	private SchemaCacheHandler $handler;

	/**
	 * Wire the handler on a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetMemoryCache();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->debug[] = [$message, $context];
			}
		);

		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturnCallback(fn (int $id) => $this->schema($id));

		$this->handler = new SchemaCacheHandler($db, $mapper, $logger);
	}

	/**
	 * Clear the static memory cache.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->resetMemoryCache();
		parent::tearDown();
	}

	/**
	 * A first write updates nothing and inserts the row with created = updated and expiry = updated + TTL.
	 *
	 * @return void
	 */
	public function testAFirstWriteInsertsAfterAnEmptyUpdate(): void {
		$schema = $this->schema(5);
		$this->handler->cacheSchemaProperties($schema, 120);

		$this->assertSame(['update', 'insert'], array_column($this->statements, 'kind'));
		$update = $this->statements[0];
		$insert = $this->statements[1];
		$this->assertSame(json_encode($schema->getProperties()), $update['set']['cache_data']);
		$this->assertSame([5, 'properties'], $update['eq']);
		$values = $insert['values'];
		$this->assertSame(5, $values['schema_id']);
		$this->assertSame('properties', $values['cache_key']);
		$this->assertSame(json_encode($schema->getProperties()), $values['cache_data']);
		$this->assertSame($values['created'], $values['updated']);
		$this->assertSame(120, $values['expires']->getTimestamp() - $values['updated']->getTimestamp());
		$this->assertEquals($update['set']['updated'], $values['updated']);
	}

	/**
	 * A write that updates a row inserts nothing.
	 *
	 * @return void
	 */
	public function testAnUpdatedRowIsNotInsertedAgain(): void {
		$this->affected['update'] = 1;
		$schema = $this->schema(6);
		$this->handler->cacheSchemaConfiguration($schema, 60);

		$this->assertSame(['update'], array_column($this->statements, 'kind'));
		$this->assertSame([6, 'configuration'], $this->statements[0]['eq']);
		$this->assertSame(json_encode($schema->getConfiguration()), $this->statements[0]['set']['cache_data']);
	}

	/**
	 * A zero TTL writes no expiry, and a TTL above eight hours is held to eight hours.
	 *
	 * @return void
	 */
	public function testTheTtlIsClampedAndZeroMeansNoExpiry(): void {
		$this->affected['update'] = 1;
		$this->handler->cacheSchemaProperties($this->schema(7), 0);
		$this->handler->cacheSchemaProperties($this->schema(7), 999999);
		$this->handler->cacheSchemaProperties($this->schema(7), -5);

		$this->assertNull($this->statements[0]['set']['expires']);
		$set = $this->statements[1]['set'];
		$this->assertSame(28800, $set['expires']->getTimestamp() - $set['updated']->getTimestamp());
		$this->assertNull($this->statements[2]['set']['expires']);
	}

	/**
	 * Caching a schema writes the schema, then its configuration, then its properties.
	 *
	 * @return void
	 */
	public function testCachingASchemaWritesThreeKeysInOrder(): void {
		$this->affected['update'] = 1;
		$this->handler->cacheSchema($this->schema(8));

		$keys = array_map(fn (array $s) => $s['eq'][1], $this->statements);
		$this->assertSame(['schema_object', 'configuration', 'properties'], $keys);
		$set = $this->statements[0]['set'];
		$this->assertSame(3600, $set['expires']->getTimestamp() - $set['updated']->getTimestamp());
		$payload = json_decode($set['cache_data'], true);
		$this->assertSame(8, $payload['id']);
		$this->assertSame('uuid-8', $payload['uuid']);
	}

	/**
	 * An expired database row is deleted and the schema is loaded from the mapper and logged.
	 *
	 * @return void
	 */
	public function testAnExpiredRowIsRemovedAndTheSchemaReloaded(): void {
		$this->fetched = ['cache_data' => '{}', 'expires' => '2000-01-01 00:00:00'];
		$this->affected['update'] = 1;

		$schema = $this->handler->getSchema(9);

		$this->assertSame(9, $schema->getId());
		$kinds = array_column($this->statements, 'kind');
		$this->assertSame(['select', 'delete', 'update', 'update', 'update'], $kinds);
		$this->assertSame([9, 'schema_object'], $this->statements[1]['eq']);
		$this->assertSame('[SchemaCacheHandler] Schema loaded from database and cached', $this->debug[0][0]);
		$this->assertDebugContext($this->debug[0][1], 9);
	}

	/**
	 * A memory hit and a cleared cache each log one debug line naming the schema.
	 *
	 * @return void
	 */
	public function testMemoryHitsAndClearsAreLogged(): void {
		$this->affected['update'] = 1;
		$this->handler->cacheSchema($this->schema(10));
		$this->handler->getSchema(10);
		$this->handler->clearSchemaCache(10);

		$messages = array_column($this->debug, 0);
		$this->assertSame(['[SchemaCacheHandler] Schema cache hit (memory)', '[SchemaCacheHandler] Cleared schema cache'], $messages);
		$this->assertDebugContext($this->debug[0][1], 10);
		$this->assertDebugContext($this->debug[1][1], 10);
	}

	/**
	 * A fresh database row is a database hit, logged once.
	 *
	 * @return void
	 */
	public function testAFreshRowIsADatabaseHit(): void {
		$this->affected['update'] = 1;
		$this->handler->cacheSchema($this->schema(11));
		$payload          = $this->statements[0]['set']['cache_data'];
		$this->statements = [];
		$this->resetMemoryCache();
		$this->fetched = ['cache_data' => $payload, 'expires' => null];

		$schema = $this->handler->getSchema(11);

		$this->assertSame(11, $schema->getId());
		$this->assertSame(['select'], array_column($this->statements, 'kind'));
		$this->assertSame('[SchemaCacheHandler] Schema cache hit (database)', $this->debug[0][0]);
		$this->assertDebugContext($this->debug[0][1], 11);
	}

	/**
	 * A debug context names this file, a line and the schema.
	 *
	 * @param array<string, mixed> $context  The logged context.
	 * @param int                  $schemaId The schema it should name.
	 *
	 * @return void
	 */
	private function assertDebugContext(array $context, int $schemaId): void {
		$this->assertSame(['file', 'line', 'schemaId'], array_keys($context));
		$this->assertStringEndsWith('SchemaCacheHandler.php', $context['file']);
		$this->assertIsInt($context['line']);
		$this->assertSame($schemaId, $context['schemaId']);
	}

	/**
	 * A schema with a known id, configuration and properties.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setUuid('uuid-' . $id);
		$schema->setTitle('Schema ' . $id);
		$schema->setConfiguration(['k' => 'v']);
		$schema->setProperties(['naam' => ['type' => 'string']]);
		return $schema;
	}

	/**
	 * A query builder that records the statement it builds.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function recordingBuilder(): IQueryBuilder {
		$index = count($this->statements);
		$this->statements[$index] = ['kind' => null, 'set' => [], 'values' => [], 'eq' => []];
		$record = function (string $field, mixed $value) use ($index): void {
			$this->statements[$index][$field] = $value;
		};

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, mixed $value) use ($index): string {
				$this->statements[$index]['eq'][] = $value;
				return $column;
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(fn () => $this->fetched);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		foreach (['select', 'update', 'insert', 'delete'] as $kind) {
			$qb->method($kind)->willReturnCallback(
				function () use ($record, $kind, $qb) {
					$record('kind', $kind);
					return $qb;
				}
			);
		}

		foreach (['from', 'where', 'andWhere'] as $chain) {
			$qb->method($chain)->willReturnSelf();
		}

		$qb->method('set')->willReturnCallback(
			function (string $column, mixed $value) use ($index, $qb) {
				$this->statements[$index]['set'][$column] = $value;
				return $qb;
			}
		);
		$qb->method('values')->willReturnCallback(
			function (array $values) use ($record, $qb) {
				$record('values', $values);
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturnCallback(fn () => $this->affected[$this->statements[$index]['kind']]);

		return $qb;
	}

	/**
	 * Empty the handler's static memory cache.
	 *
	 * @return void
	 */
	private function resetMemoryCache(): void {
		$property = (new ReflectionClass(SchemaCacheHandler::class))->getProperty('memoryCache');
		$property->setAccessible(true);
		$property->setValue(null, []);
	}
}
