<?php

/**
 * Golden test pinning the access-control posture of MagicSearchHandler's two read paths.
 *
 * The QueryBuilder path (buildFilteredQuery(), applyAccessControlToQuery())
 * and the string-SQL path (buildWhereConditionsSql()) render the organisation
 * boundary, the RBAC filter and the lifecycle lenses from the request's
 * `_rbac`, `_multitenancy` and `_multitenancy_explicit` flags. This test
 * records every collaborator decision and every WHERE fragment each path
 * emits across a matrix of postures and RBAC answers, so a refactor of the
 * parameters these flags travel in cannot change an allowed or a refused
 * outcome without failing here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses \OCA\OpenRegister\Db\MagicMapper\OrganisationScopeCondition
 * @uses \OCA\OpenRegister\Db\MagicMapper\OrganisationlessAdmission
 * @uses \OCA\OpenRegister\Db\MagicMapper\ArrayElementFilter
 * @uses \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 */
final class MagicSearchHandlerAccessPostureGoldenTest extends TestCase {

	/**
	 * Every decision and fragment one read emitted, in order.
	 *
	 * @var array<int, string>
	 */
	private array $events = [];

	/**
	 * The request postures under test.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function postures(): array {
		return [
			'default'         => [],
			'rbac off'        => ['_rbac' => false],
			'mt off'          => ['_multitenancy' => false],
			'explicit'        => ['_multitenancy' => true, '_multitenancy_explicit' => true],
			'explicit string' => ['_multitenancy_explicit' => 'true'],
			'both off'        => ['_rbac' => false, '_multitenancy' => false],
			'register key'    => ['register' => '7'],
			'drafts only'     => ['_drafts' => 'true'],
			'drafts any'      => ['_drafts' => 'any'],
			'archived'        => ['_archived' => 'true', '_deleted' => 'true'],
			'archived any'    => ['_archived' => 'any', '_deleted' => 'any'],
		];
	}//end postures()

	/**
	 * The RBAC answers under test: bypass, grants, cross branch, cascade, public lift, scope.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function answers(): array {
		$in   = ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']];
		$none = ['mode' => MagicOrganizationHandler::SCOPE_NONE, 'uuids' => []];
		$all  = ['mode' => MagicOrganizationHandler::SCOPE_ALL, 'uuids' => []];
		return [
			'plain member'    => [false, false, false, false, false, $in, false],
			'no organisation' => [false, false, false, false, false, $none, false],
			'rules bypass'    => [true, false, false, false, false, $in, false],
			'bypass + grants' => [true, true, false, false, false, $in, false],
			'cross branch'    => [false, false, true, false, false, $in, false],
			'cascade'         => [false, false, false, true, false, $none, false],
			'admin all'       => [false, false, false, false, false, $all, false],
			'public lifted'   => [false, false, false, false, true, $in, true],
			'public kept'     => [false, false, false, false, false, $in, true],
		];
	}//end answers()

	/**
	 * Build a handler whose RBAC and organisation answers are dictated and recorded.
	 *
	 * @param array<int, mixed> $answer One row of answers().
	 *
	 * @return MagicSearchHandler
	 */
	private function handler(array $answer): MagicSearchHandler {
		[$bypass, $grants, $cross, $cascade, $lifts, $scope] = $answer;

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('getCurrentUserId')->willReturn('alice');
		$rbac->method('hasConditionalRulesBypassingMultitenancy')->willReturn($bypass);
		$rbac->method('currentCallerHoldsObjectGrants')->willReturn($grants);
		$rbac->method('hasCrossOrganisationBranch')->willReturn($cross);
		$rbac->method('getCrossOrganisationBranch')->willReturn('CROSS');
		$rbac->method('getCrossOrganisationBranchSql')->willReturn('CROSS_SQL');
		$rbac->method('admitsOrganisationlessRowsThroughCascade')->willReturn($cascade);
		$rbac->method('publicReadLiftsOrganisationFilter')->willReturn($lifts);
		$rbac->method('applyRbacFilters')->willReturnCallback(
			function ($qb, $schema, $action, $tableName = null): void {
				$this->events[] = 'rbac '.$action.' '.var_export($tableName, true);
			}
		);
		$rbac->method('buildRbacConditionsSql')->willReturnCallback(
			function ($schema, $action, $columnPrefix = '', $tableName = null): array {
				$this->events[] = 'rbac-sql '.$action.' '.var_export($tableName, true);
				return ['bypass' => false, 'conditions' => ['RBAC_SQL']];
			}
		);

		$organisation = $this->getMockBuilder(MagicOrganizationHandler::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveOrganizationScope', 'isAdminOverrideEnabled'])
			->getMock();
		$organisation->method('isAdminOverrideEnabled')->willReturn(true);
		$organisation->method('resolveOrganizationScope')->willReturnCallback(
			function (bool $adminBypassEnabled = false, ?int $registerId = null, ?int $schemaId = null) use ($scope): array {
				$this->events[] = 'scope '.var_export($adminBypassEnabled, true).' '.var_export($registerId, true).' '.var_export($schemaId, true);
				return $scope;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

		return new MagicSearchHandler(
			$db,
			$this->createMock(LoggerInterface::class),
			$rbac,
			$organisation,
			$this->createMock(SchemaTypeConverter::class),
			$this->createMock(DateTimeNormalizer::class),
			$this->createMock(RelatedRowQueryApplier::class)
		);
	}//end handler()

	/**
	 * A query builder that records every andWhere() as text.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturnCallback(static fn ($x, $y): string => $x.' IN '.$y);
		$expr->method('eq')->willReturnCallback(static fn ($x, $y): string => $x.' = '.$y);
		$expr->method('isNull')->willReturnCallback(static fn ($x): string => $x.' IS NULL');
		$expr->method('isNotNull')->willReturnCallback(static fn ($x): string => $x.' IS NOT NULL');
		$expr->method('orX')->willReturnCallback(fn (...$parts): ICompositeExpression => $this->composite(parts: $parts));

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'".(string) $value."'");

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('getConnection')->willReturn($connection);
		$qb->method('from')->willReturnSelf();
		$qb->method('createFunction')->willReturnCallback(static fn ($sql): string => (string) $sql);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => ':'.json_encode($value)
		);
		$qb->method('andWhere')->willReturnCallback(
			function ($condition) use (&$qb) {
				$this->events[] = 'where '.(string) $condition;
				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * A composite OR expression that renders to a string.
	 *
	 * @param array<int, mixed> $parts The parts.
	 *
	 * @return ICompositeExpression
	 */
	private function composite(array $parts): ICompositeExpression {
		return new class($parts) implements ICompositeExpression {
			/**
			 * @param array<int, mixed> $parts The parts.
			 */
			public function __construct(private array $parts) {
			}

			public function addMultiple(array $parts = []): ICompositeExpression {
				return $this;
			}

			public function add($part): ICompositeExpression {
				return $this;
			}

			public function count(): int {
				return count($this->parts);
			}

			public function getType(): string {
				return 'OR';
			}

			public function __toString(): string {
				return '('.implode(' OR ', array_map('strval', $this->parts)).')';
			}
		};
	}//end composite()

	/**
	 * The schema under test, with or without a public read rule.
	 *
	 * @param bool $public Whether the schema grants `public` read.
	 *
	 * @return Schema
	 */
	private function schema(bool $public): Schema {
		$schema = new Schema();
		$schema->setId(5);
		if ($public === true) {
			$schema->setAuthorization(['read' => ['public']]);
		}

		return $schema;
	}//end schema()

	/**
	 * Run every posture against every answer on one path.
	 *
	 * @param callable $read Receives (handler, query, schema), performs one read.
	 *
	 * @return array<string, array<int, string>|string>
	 */
	private function record(callable $read): array {
		$recorded = [];
		foreach (self::answers() as $answerName => $answer) {
			foreach (self::postures() as $postureName => $query) {
				$this->events = [];
				try {
					$read($this->handler(answer: $answer), $query, $this->schema(public: $answer[6]));
					$recorded[$answerName.' / '.$postureName] = $this->events;
				} catch (TypeError $error) {
					$recorded[$answerName.' / '.$postureName] = 'refused: TypeError';
				}
			}
		}

		return $recorded;
	}//end record()

	/**
	 * The fixture holding the recorded outcomes.
	 *
	 * @param string $name The fixture name.
	 *
	 * @return array<string, mixed>
	 */
	private function golden(string $name): array {
		$file = __DIR__.'/fixtures/access-posture-'.$name.'.json';
		if (getenv('UPDATE_GOLDEN') === '1') {
			return [];
		}

		return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	}//end golden()

	/**
	 * Compare the outcomes with the fixture, or write the fixture on request.
	 *
	 * @param string               $name     The fixture name.
	 * @param array<string, mixed> $recorded The recorded outcomes.
	 *
	 * @return void
	 */
	private function assertGolden(string $name, array $recorded): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(
				__DIR__.'/fixtures/access-posture-'.$name.'.json',
				json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
			);
			$this->markTestSkipped('golden written');
		}

		$this->assertSame($this->golden(name: $name), $recorded);
	}//end assertGolden()

	/**
	 * The QueryBuilder list path: organisation boundary, RBAC filter and lifecycle lenses.
	 *
	 * @return void
	 */
	public function testTheFilteredQueryPostureIsPinned(): void {
		$recorded = $this->record(
			static function (MagicSearchHandler $handler, array $query, Schema $schema): void {
				$handler->buildFilteredQuery(query: $query, schema: $schema, tableName: 'openregister_table_1_5', registerId: (isset($query['register']) === true ? null : 3));
			}
		);

		$this->assertGolden(name: 'filtered-query', recorded: $recorded);
	}//end testTheFilteredQueryPostureIsPinned()

	/**
	 * The single-object read path: the same boundary with explicit flags.
	 *
	 * @return void
	 */
	public function testTheSingleObjectPostureIsPinned(): void {
		$recorded = $this->record(
			static function (MagicSearchHandler $handler, array $query, Schema $schema): void {
				$handler->applyAccessControlToQuery(
					qb: (new \ReflectionProperty($handler, 'db'))->getValue($handler)->getQueryBuilder(),
					schema: $schema,
					_rbac: (($query['_rbac'] ?? true) === true),
					_multitenancy: (($query['_multitenancy'] ?? true) === true),
					registerId: 3,
					tableName: 'openregister_table_1_5'
				);
			}
		);

		$this->assertGolden(name: 'single-object', recorded: $recorded);
	}//end testTheSingleObjectPostureIsPinned()

	/**
	 * The string-SQL path: the same decisions rendered as text, raw flags included.
	 *
	 * @return void
	 */
	public function testTheUnionConditionsPostureIsPinned(): void {
		$recorded = $this->record(
			function (MagicSearchHandler $handler, array $query, Schema $schema): void {
				$conditions   = $handler->buildWhereConditionsSql(query: $query, schema: $schema, registerId: (isset($query['register']) === true ? null : 3), tableName: 'openregister_table_1_5');
				$this->events = array_merge($this->events, $conditions);
			}
		);

		$this->assertGolden(name: 'union-conditions', recorded: $recorded);
	}//end testTheUnionConditionsPostureIsPinned()

	/**
	 * Unreadable flags: the string path fails closed, the QueryBuilder path refuses.
	 *
	 * @return void
	 */
	public function testUnreadableFlagsAreRefusedOrFailClosed(): void {
		$answer = self::answers()['rules bypass'];
		$recorded = [];
		foreach (['string false' => ['_rbac' => 'false'], 'garbage rbac' => ['_rbac' => ['x']], 'garbage mt' => ['_multitenancy' => 'nope']] as $name => $query) {
			$this->events = [];
			try {
				$this->handler(answer: $answer)->buildFilteredQuery(query: $query, schema: $this->schema(public: false), tableName: 'openregister_table_1_5', registerId: 3);
				$recorded['qb '.$name] = $this->events;
			} catch (TypeError $error) {
				$recorded['qb '.$name] = 'refused: TypeError';
			}

			$this->events = [];
			$recorded['sql '.$name] = $this->handler(answer: $answer)->buildWhereConditionsSql(query: $query, schema: $this->schema(public: false), registerId: 3, tableName: 'openregister_table_1_5');
			$recorded['sql '.$name.' events'] = $this->events;
		}

		$this->assertGolden(name: 'unreadable-flags', recorded: $recorded);
	}//end testUnreadableFlagsAreRefusedOrFailClosed()
}//end class
