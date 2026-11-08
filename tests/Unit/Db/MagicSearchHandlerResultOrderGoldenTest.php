<?php

/**
 * Golden for the result order a magic-table search applies.
 *
 * Records every ORDER BY expression, direction and bound parameter that
 * MagicSearchHandler::applyResultOrder() adds, per platform, requested
 * order, search term, read history, date columns and language chain.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Result order, recorded per case.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\DateTimeNormalizer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SortRequest
 */
class MagicSearchHandlerResultOrderGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/search-result-order-golden.json';

	/**
	 * Every builder call, rendered.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	public function testResultOrderMatchesGolden(): void {
		$platforms = ['sqlite' => new SqlitePlatform(), 'mysql' => new MySQLPlatform(), 'postgres' => new PostgreSQLPlatform()];
		$orders = [
			'none' => [],
			'relevance' => ['_relevance' => 'desc'],
			'self fields' => ['@self.created' => 'desc', '@self.lastUserActivity' => 'asc', '@self.name' => 'ASC'],
			'metadata' => ['_created' => 'desc', '_name' => 'bogus', '_uuid' => 'asc', '_owner' => 'Desc', '_id' => 'asc'],
			'properties' => ['title' => 'asc', 'amount' => 'desc', 'when' => 'asc', 'flag' => 'asc', 'tags' => 'desc'],
			'translatable' => ['label' => 'desc', 'title' => 'asc'],
			'unknown' => ['missing' => 'asc', 'relevance' => 'asc'],
		];
		$variants = [
			'plain' => [null, null, null, [], false],
			'search + history' => ['zoek', ['u1' => '2026-01-01', 'u2' => '2026-01-02'], ['when'], ['nl', 'en'], true],
			'history only' => [null, ['u9' => 'x'], [], ['en'], false],
			'pg_trgm without search' => [null, null, null, [], true],
			'search without pg_trgm' => ['zoek', null, null, [], false],
		];

		$actual = [];
		foreach ($platforms as $platformName => $platform) {
			foreach ($orders as $orderName => $order) {
				foreach ($variants as $variantName => [$searchTerm, $recent, $dateColumns, $languages, $trgm]) {
					$this->calls = [];
					$args = [
						'order' => $order,
						'schema' => $this->schema(),
						'searchTerm' => $searchTerm,
						'recentViews' => $recent,
						'dateTimeColumns' => $dateColumns,
						'sortLanguages' => $languages,
					];
					$this->applyResultOrder(handler: $this->handler($platform, $trgm), args: $args);
					$actual["{$platformName} / {$orderName} / {$variantName}"] = $this->calls;
				}
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testResultOrderMatchesGolden()

	/**
	 * Call applyResultOrder() with either its positional arguments or one sort request.
	 *
	 * @param MagicSearchHandler   $handler The handler.
	 * @param array<string, mixed> $args    The arguments by name.
	 *
	 * @return void
	 */
	private function applyResultOrder(MagicSearchHandler $handler, array $args): void {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyResultOrder');
		$qb = $this->queryBuilder();
		if ($method->getNumberOfParameters() === 2) {
			$requestClass = 'OCA\OpenRegister\Db\MagicMapper\SortRequest';
			$request = new $requestClass(order: $args['order'], schema: $args['schema']);
			foreach (array_diff_key($args, ['order' => true, 'schema' => true]) as $field => $value) {
				$request->$field = $value;
			}

			$method->invoke($handler, $qb, $request);
			return;
		}

		$method->invoke($handler, $qb, ...array_values($args));
	}//end applyResultOrder()

	/**
	 * A schema with one property of each sortable kind.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'title' => ['type' => 'string'],
				'amount' => ['type' => 'number'],
				'when' => ['type' => 'string', 'format' => 'date-time'],
				'flag' => ['type' => 'boolean'],
				'tags' => ['type' => 'array'],
				'label' => ['type' => 'string', 'translatable' => true],
				'missing' => null,
			]
		);
		return $schema;
	}//end schema()

	/**
	 * The handler on a connection reporting a platform; pg_trgm present when asked.
	 *
	 * @param object $platform The Doctrine platform.
	 * @param bool   $trgm     Whether pg_trgm is installed.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(object $platform, bool $trgm): MagicSearchHandler {
		$logger = $this->createMock(LoggerInterface::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($trgm === true ? 1 : 0);
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturn($result);
		$db->method('prepare')->willReturn($stmt);

		return new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);
	}//end handler()

	/**
	 * A builder that records ORDER BY, functions and parameters.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getColumnName')->willReturnCallback(
			static fn (string $column, string $alias = ''): string => ($alias === '' ? '' : "\"{$alias}\".") . "\"{$column}\""
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $call): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($call);
				return $function;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value): string {
				$this->calls[] = 'param ' . json_encode($value);
				return ':p' . count($this->calls);
			}
		);
		$qb->method('addOrderBy')->willReturnCallback(
			function ($sort, $direction = null) use ($qb) {
				$this->calls[] = 'order ' . (string) $sort . ' ' . (string) $direction;
				return $qb;
			}
		);
		return $qb;
	}//end queryBuilder()
}//end class
