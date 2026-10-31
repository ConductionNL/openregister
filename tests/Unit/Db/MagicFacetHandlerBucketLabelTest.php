<?php

/**
 * Tests for how a terms facet labels its UUID buckets.
 *
 * Every UUID bucket of a facet is named by one batch lookup. A UUID that batch
 * could not name (a dangling reference, or an object the caller may not read)
 * must not be looked up again per bucket: each such lookup scans every magic
 * table, so a facet over a few dozen statuses cost close to a minute.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\TermsFacetUnion;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks the single name lookup behind a terms facet's UUID buckets.
 */
class MagicFacetHandlerBucketLabelTest extends TestCase {

	private const KNOWN = '11111111-1111-4111-8111-111111111111';

	private const DANGLING_A = '22222222-2222-4222-8222-222222222222';

	private const DANGLING_B = '33333333-3333-4333-8333-333333333333';

	/**
	 * Run getTermsFacet() over the given rows with the given name resolver.
	 *
	 * @param array<int, array<string, mixed>> $rows         The facet rows the database returns.
	 * @param CacheHandler                     $cacheHandler The name resolver.
	 * @param string                           $column       The faceted column.
	 * @param array<string, array>             $properties   The schema's property definitions.
	 *
	 * @return array<string, mixed> The facet result.
	 */
	private function facetOver(
		array $rows,
		CacheHandler $cacheHandler,
		string $column = 'status',
		array $properties = ['status' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'statusType']],
	): array {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));

		$probe = $this->createMock(IPreparedStatement::class);
		$probe->method('execute')->willReturn($this->createMock(IResult::class));
		$probe->method('fetch')->willReturnOnConsecutiveCalls(['col' => $column], false);
		$db->method('prepare')->willReturn($probe);

		$result = $this->createMock(IResult::class);
		$rows[] = false;
		$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$queryBuilder->method('createFunction')->willReturnCallback(static fn (string $sql): string => $sql);
		foreach (['selectAlias', 'addSelect', 'from', 'groupBy', 'orderBy', 'addOrderBy', 'where', 'andWhere'] as $fluent) {
			$queryBuilder->method($fluent)->willReturn($queryBuilder);
		}

		$queryBuilder->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);

		$handler = new MagicFacetHandler(
			db: $db,
			logger: $this->createMock(LoggerInterface::class),
			cacheHandler: $cacheHandler
		);

		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		$method = new ReflectionMethod(MagicFacetHandler::class, 'getTermsFacet');
		$method->setAccessible(true);

		return $method->invoke(
			$handler,
			'openregister_table_1_2',
			$column,
			[],
			false,
			$this->createMock(Register::class),
			$schema
		);
	}//end facetOver()

	/**
	 * The UUID buckets are named by exactly one lookup, also when some of them
	 * cannot be named.
	 *
	 * @return void
	 */
	public function testUnnamedUuidsAreNotLookedUpAgainPerBucket(): void {
		$cacheHandler = $this->createMock(CacheHandler::class);
		$cacheHandler->expects($this->once())
			->method('getMultipleObjectNames')
			->with([self::KNOWN, self::DANGLING_A, self::DANGLING_B])
			->willReturn([self::KNOWN => 'Ontvangen']);

		$facet = $this->facetOver(
			[
				['facet_value' => self::KNOWN, 'doc_count' => 5],
				['facet_value' => self::DANGLING_A, 'doc_count' => 3],
				['facet_value' => self::DANGLING_B, 'doc_count' => 1],
			],
			$cacheHandler
		);

		$labels = array_column($facet['buckets'], 'label', 'key');
		$this->assertSame('Ontvangen', $labels[self::KNOWN]);
		$this->assertSame('22222222...', $labels[self::DANGLING_A]);
		$this->assertSame('33333333...', $labels[self::DANGLING_B]);
	}//end testUnnamedUuidsAreNotLookedUpAgainPerBucket()

	/**
	 * A plain value is labelled with itself and never reaches the name lookup.
	 *
	 * @return void
	 */
	public function testPlainValuesKeepTheirOwnLabel(): void {
		$cacheHandler = $this->createMock(CacheHandler::class);
		$cacheHandler->expects($this->never())->method('getMultipleObjectNames');

		$facet = $this->facetOver(
			[
				['facet_value' => 'behandelaars', 'doc_count' => 4],
				['facet_value' => 'zuiddrecht-kcc', 'doc_count' => 2],
			],
			$cacheHandler
		);

		$this->assertSame(
			['behandelaars' => 'behandelaars', 'zuiddrecht-kcc' => 'zuiddrecht-kcc'],
			array_column($facet['buckets'], 'label', 'key')
		);
	}//end testPlainValuesKeepTheirOwnLabel()

	/**
	 * A property with a referenceType holds an integration's ids, so a UUID
	 * among its values is never looked up as an object name.
	 *
	 * @return void
	 */
	public function testIntegrationReferencesAreNotLookedUpAsObjects(): void {
		$cacheHandler = $this->createMock(CacheHandler::class);
		$cacheHandler->expects($this->never())->method('getMultipleObjectNames');

		$facet = $this->facetOver(
			[
				['facet_value' => self::DANGLING_A, 'doc_count' => 2],
				['facet_value' => 'behandelaars', 'doc_count' => 4],
			],
			$cacheHandler,
			'assigned_group',
			['assignedGroup' => ['type' => 'string', 'referenceType' => 'nextcloud-group']]
		);

		$this->assertEquals(
			[self::DANGLING_A => self::DANGLING_A, 'behandelaars' => 'behandelaars'],
			array_column($facet['buckets'], 'label', 'key')
		);
	}//end testIntegrationReferencesAreNotLookedUpAsObjects()

	/**
	 * Run getTermsFacetUnion() over the given rows, one table per property set.
	 *
	 * @param array<int, array<string, mixed>>     $rows         The union rows the database returns.
	 * @param CacheHandler                         $cacheHandler The name resolver.
	 * @param string                               $column       The faceted column.
	 * @param array<int, array<string, array>>     $propertySets One schema's property definitions per table.
	 *
	 * @return array<string, mixed> The facet result.
	 */
	private function unionFacetOver(
		array $rows,
		CacheHandler $cacheHandler,
		string $column,
		array $propertySets,
	): array {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));

		$rows[] = false;
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($column, $rows): IPreparedStatement {
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturn($this->createMock(IResult::class));
				if (str_contains($sql, 'information_schema') === true) {
					$statement->method('fetch')->willReturnOnConsecutiveCalls(['col' => $column], false);
					return $statement;
				}

				$statement->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
				return $statement;
			}
		);

		$handler = new MagicFacetHandler(
			db: $db,
			logger: $this->createMock(LoggerInterface::class),
			cacheHandler: $cacheHandler
		);

		$tableConfigs = [];
		foreach ($propertySets as $index => $properties) {
			$schema = $this->createMock(Schema::class);
			$schema->method('getProperties')->willReturn($properties);
			$tableConfigs[] = [
				'tableName' => 'openregister_table_1_'.($index + 2),
				'schema' => $schema,
				'register' => $this->createMock(Register::class),
			];
		}

		$method = new ReflectionMethod(MagicFacetHandler::class, 'getTermsFacetUnion');
		$method->setAccessible(true);

		return $method->invoke($handler, new TermsFacetUnion(tableConfigs: $tableConfigs, field: $column, baseQuery: []));
	}//end unionFacetOver()

	/**
	 * On the multi-schema union path a referenceType property is not looked up
	 * as object names either.
	 *
	 * @return void
	 */
	public function testUnionIntegrationReferencesAreNotLookedUpAsObjects(): void {
		$cacheHandler = $this->createMock(CacheHandler::class);
		$cacheHandler->expects($this->never())->method('getMultipleObjectNames');

		$facet = $this->unionFacetOver(
			[
				['facet_value' => self::DANGLING_A, 'doc_count' => 2],
				['facet_value' => 'behandelaars', 'doc_count' => 4],
			],
			$cacheHandler,
			'assigned_group',
			[['assignedGroup' => ['type' => 'string', 'referenceType' => 'nextcloud-group']]]
		);

		$this->assertEquals(
			[self::DANGLING_A => self::DANGLING_A, 'behandelaars' => 'behandelaars'],
			array_column($facet['buckets'], 'label', 'key')
		);
	}//end testUnionIntegrationReferencesAreNotLookedUpAsObjects()

	/**
	 * When one schema of the union holds object references in the column, the
	 * name lookup still runs, even if the first schema declares a referenceType.
	 *
	 * @return void
	 */
	public function testUnionKeepsTheLookupWhenAnySchemaHoldsObjectReferences(): void {
		$cacheHandler = $this->createMock(CacheHandler::class);
		$cacheHandler->expects($this->once())
			->method('getMultipleObjectNames')
			->with([self::KNOWN])
			->willReturn([self::KNOWN => 'Ontvangen']);

		$facet = $this->unionFacetOver(
			[['facet_value' => self::KNOWN, 'doc_count' => 3]],
			$cacheHandler,
			'assigned_group',
			[
				['assignedGroup' => ['type' => 'string', 'referenceType' => 'nextcloud-group']],
				['assignedGroup' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'group']],
			]
		);

		$this->assertSame('Ontvangen', array_column($facet['buckets'], 'label', 'key')[self::KNOWN]);
	}//end testUnionKeepsTheLookupWhenAnySchemaHoldsObjectReferences()
}//end class
