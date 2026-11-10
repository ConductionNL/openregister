<?php

/**
 * Characterisation: the access and lifecycle conditions of MagicSearchHandler.
 *
 * Pins, over a grid of inputs, what the organisation boundary, the RBAC
 * condition, the archive, deleted and draft lenses add to a query, on the
 * raw-SQL path (boundaryConditionsSql(), buildOrganizationConditionSql(),
 * lifecycleConditionsSql(), archivedConditionSql()) and on the QueryBuilder
 * path (applyBasicFilters()). Allowed and refused outcomes are both in the
 * fixture: `1=0` / `1 = 0` is a refusal, a missing condition is an opening.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
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

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\ConditionSqlRun;
use OCA\OpenRegister\Db\MagicMapper\LifecycleColumns;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\OrganisationBoundary;
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden access and lifecycle conditions.
 */
class MagicSearchHandlerAccessConditionsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/access-conditions-golden.json';

	/**
	 * Predicates captured from the QueryBuilder path.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	/**
	 * Build a handler whose collaborators answer as `$state` says.
	 *
	 * @param array $state The collaborator answers.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function handler(array $state): MagicSearchHandler {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'".(string) $value."'");
		$builder = $this->createMock(IQueryBuilder::class);
		$builder->method('getConnection')->willReturn($connection);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->method('getQueryBuilder')->willReturn($builder);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('getCurrentUserId')->willReturn($state['user'] ?? null);
		$rbac->method('hasConditionalRulesBypassingMultitenancy')->willReturn($state['conditionalBypass'] ?? false);
		$rbac->method('currentCallerHoldsObjectGrants')->willReturn($state['grants'] ?? false);
		$rbac->method('publicReadLiftsOrganisationFilter')->willReturn(false);
		$rbac->method('hasCrossOrganisationBranch')->willReturn($state['cross'] ?? false);
		$rbac->method('getCrossOrganisationBranchSql')->willReturn('cross_branch');
		$rbac->method('buildRbacConditionsSql')->willReturnCallback(
			static fn (Schema $schema, string $action = 'read', string $columnPrefix = '', ?string $tableName = null): array => [
				'bypass'     => ($state['rbacSql'] ?? 'bypass') === 'bypass',
				'conditions' => ($state['rbacSql'] ?? 'bypass') === 'conditions' ? ["rbac_a({$action},{$tableName})", 'rbac_b'] : [],
			]
		);
		$rbac->method('admitsOrganisationlessRowsThroughCascade')->willReturn($state['orgless'] ?? false);

		$organisations = $this->createMock(MagicOrganizationHandler::class);
		$organisations->method('isAdminOverrideEnabled')->willReturn(true);
		$organisations->method('resolveOrganizationScope')->willReturnCallback(
			static fn (bool $adminBypassEnabled, ?int $registerId = null, ?int $schemaId = null): array => $state['scope'] ?? [
				'mode'  => MagicOrganizationHandler::SCOPE_IN,
				'uuids' => ['r'.var_export($registerId, true).'s'.var_export($schemaId, true).'a'.var_export($adminBypassEnabled, true)],
			]
		);

		$healer = $this->createMock(MagicMetadataColumnHealer::class);
		$healer->method('missingFor')->willReturn($state['missing'] ?? []);

		$logger = $this->createMock(LoggerInterface::class);
		return new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $rbac,
			organizationHandler: $organisations,
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class),
			metadataColumns: $healer
		);
	}//end handler()

	/**
	 * A schema with an id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(12);
		return $schema;
	}//end schema()

	/**
	 * A raw-path condition pass.
	 *
	 * @param array    $query      The query.
	 * @param int|null $registerId The table's register id.
	 *
	 * @return ConditionSqlRun The pass.
	 */
	private function conditionRun(array $query, ?int $registerId = null): ConditionSqlRun {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'".(string) $value."'");

		return new ConditionSqlRun(
			query: $query,
			table: new MagicReadTable(schema: $this->schema(), registerId: $registerId, tableName: 'oc_t'),
			connection: $connection,
			isPostgres: true
		);
	}//end conditionRun()

	/**
	 * A query builder that records every predicate it is given.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('isNull')->willReturnCallback(static fn ($c): string => "isNull({$c})");
		$expr->method('isNotNull')->willReturnCallback(static fn ($c): string => "isNotNull({$c})");

		$params = 0;
		$qb     = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnCallback(static fn (string $sql): string => "fn({$sql})");
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value) use (&$params): string {
				$params++;
				return ":p{$params}=".json_encode($value);
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string) $predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * Run a private method, rendering a thrown exception as its outcome.
	 *
	 * @param MagicSearchHandler $handler The handler.
	 * @param string             $method  The method.
	 * @param mixed              ...$args The arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(MagicSearchHandler $handler, string $method, mixed ...$args): mixed {
		try {
			return (new ReflectionMethod(MagicSearchHandler::class, $method))->invoke($handler, ...$args);
		} catch (\Throwable $e) {
			return 'threw '.get_class($e).': '.$e->getMessage();
		}
	}//end call()

	/**
	 * Every outcome over the grid.
	 *
	 * @return array<string, mixed> The outcomes by case name.
	 */
	private function outcomes(): array {
		$actual = [];

		foreach ([MagicSearchHandler::ARCHIVED_EXCLUDE, MagicSearchHandler::ARCHIVED_ONLY, MagicSearchHandler::ARCHIVED_ANY] as $mode) {
			foreach ([[], ['_archived']] as $missing) {
				foreach (['t.', ''] as $prefix) {
					$actual["archived {$mode} missing=".json_encode($missing)." prefix={$prefix}"] = $this->call(
						$this->handler([]),
						'archivedConditionSql',
						$mode,
						new LifecycleColumns(prefix: $prefix, missing: $missing)
					);
				}
			}
		}

		$scopes = [
			'all'          => ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => ['x']],
			'null only'    => ['mode' => MagicOrganizationHandler::SCOPE_NULL_ONLY, 'uuids' => []],
			'in'           => ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['a', '', 5, 'b']],
			'in empty'     => ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['', null]],
			'in no uuids'  => ['mode' => MagicOrganizationHandler::SCOPE_IN],
			'in or null'   => ['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['a']],
			'none'         => ['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => ['a']],
			'no mode'      => ['uuids' => ['a']],
			'unknown mode' => ['mode' => 'everything', 'uuids' => ['a']],
		];
		foreach ($scopes as $name => $scope) {
			foreach ([false, true] as $withOrgless) {
				$connection = $this->createMock(IDBConnection::class);
				$connection->method('quote')->willReturnCallback(static fn ($value): string => "'".(string) $value."'");
				$actual["organisation {$name} withOrgless=".var_export($withOrgless, true)] = $this->call(
					$this->handler(['scope' => $scope]),
					'buildOrganizationConditionSql',
					new OrganisationBoundary(registerId: 3, schemaId: 12, withOrgless: $withOrgless),
					$connection
				);
			}
		}

		$queries = [
			'default'          => [],
			'archived only'    => ['_archived' => 'true'],
			'archived any'     => ['_archived' => 'any'],
			'deleted'          => ['_deleted' => 'true'],
			'include deleted'  => ['_includeDeleted' => '1'],
			'drafts only'      => ['_drafts' => 'only'],
			'drafts any'       => ['_drafts' => 'any'],
		];
		foreach ($queries as $name => $query) {
			foreach ([null, '', 'alice'] as $user) {
				foreach ([[], ['_archived', '_status', '_deleted']] as $missing) {
					$key   = "{$name} user=".var_export($user, true).' missing='.json_encode($missing);
					$state = ['user' => $user, 'missing' => $missing];
					$actual["lifecycle {$key}"] = $this->call($this->handler($state), 'lifecycleConditionsSql', $this->conditionRun(query: $query));

					$this->captured = [];
					$outcome        = $this->call(
						$this->handler($state),
						'applyBasicFilters',
						new TableFilterRun(qb: $this->queryBuilder(), query: $query, table: new MagicReadTable(schema: $this->schema(), tableName: 'oc_t'))
					);
					$actual["basic {$key}"] = [$outcome, $this->captured];
				}
			}
		}

		foreach ([true, false, 'false'] as $rbacFlag) {
			foreach ([true, false, 'false'] as $tenancy) {
				foreach ([false, 'true'] as $explicit) {
					foreach ([false, true] as $conditionalBypass) {
						foreach ([false, true] as $cross) {
							foreach (['bypass', 'conditions', 'empty'] as $rbacSql) {
								foreach ([false, true] as $orgless) {
									$query = ['_rbac' => $rbacFlag, '_multitenancy' => $tenancy, '_multitenancy_explicit' => $explicit, 'register' => '7'];
									$state = ['conditionalBypass' => $conditionalBypass, 'cross' => $cross, 'rbacSql' => $rbacSql, 'orgless' => $orgless];
									$key   = 'boundary '.json_encode($query).' '.json_encode($state);
									$actual[$key] = $this->call($this->handler($state), 'boundaryConditionsSql', $this->conditionRun(query: $query));
								}
							}
						}
					}
				}
			}
		}

		$actual['boundary table register wins'] = $this->call($this->handler(['rbacSql' => 'bypass']), 'boundaryConditionsSql', $this->conditionRun(query: ['register' => '7'], registerId: 2));
		$actual['boundary defaults']            = $this->call($this->handler(['rbacSql' => 'conditions']), 'boundaryConditionsSql', $this->conditionRun(query: []));
		$actual['boundary object grants']       = $this->call($this->handler(['conditionalBypass' => true, 'grants' => true]), 'boundaryConditionsSql', $this->conditionRun(query: []));

		return $actual;
	}//end outcomes()

	/**
	 * Every outcome matches the fixture recorded on the code before the split.
	 *
	 * @return void
	 */
	public function testAccessConditionsMatchGolden(): void {
		$actual = $this->outcomes();
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testAccessConditionsMatchGolden()
}//end class
