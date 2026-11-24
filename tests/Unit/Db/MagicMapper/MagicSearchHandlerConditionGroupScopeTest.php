<?php

/**
 * The `_where` condition tree cannot widen RBAC or the organisation boundary:
 * on the list query both stay their own conditions beside the tree, so an OR
 * inside the tree (here with an `isnull` leaf, which matches rows of every
 * organisation) only narrows what the boundary already admits.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\OrganisationBoundary;
use OCA\OpenRegister\Db\MagicMapper\RbacFilterCall;
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

/**
 * RBAC and organisation scoping stay outside the `_where` tree.
 */
class MagicSearchHandlerConditionGroupScopeTest extends TestCase {

	private const PROPERTIES = [
		'place'    => ['type' => 'string'],
		'industry' => ['type' => 'string'],
	];

	/**
	 * Place Zuiddrecht, OR no industry at all.
	 */
	private const TREE = [
		'type'     => 'OR',
		'children' => [
			['field' => 'place', 'operator' => 'eq', 'value' => 'Zuiddrecht'],
			['field' => 'industry', 'operator' => 'isnull', 'value' => true],
		],
	];

	/**
	 * Every condition added to the list query, rendered, in order.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	/**
	 * A handler whose organisation boundary scopes to org-1 and whose RBAC
	 * filter adds its own condition, both through the list query's andWhere().
	 *
	 * @return MagicSearchHandler
	 */
	private function handler(): MagicSearchHandler {
		$qb = $this->queryBuilder();
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->method('getQueryBuilder')->willReturn($qb);

		$organisations = $this->createMock(MagicOrganizationHandler::class);
		$organisations->method('applyOrganizationFilter')->willReturnCallback(
			static function (IQueryBuilder $qb, OrganisationBoundary $boundary): void {
				$qb->andWhere('ORGANISATION(org-1)');
			}
		);
		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('applyRbacFilters')->willReturnCallback(
			static function (RbacFilterCall $call): void {
				$call->qb->andWhere('RBAC(alice)');
			}
		);
		$relatedRows = $this->createMock(RelatedRowQueryApplier::class);
		$relatedRows->method('mayReadProperty')->willReturn(true);

		return new MagicSearchHandler(
			db: $db,
			logger: $this->createMock(LoggerInterface::class),
			rbacHandler: $rbac,
			organizationHandler: $organisations,
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizer::class),
			relatedRows: $relatedRows
		);
	}//end handler()

	/**
	 * A query builder whose expressions render as text and whose andWhere()
	 * records each condition it is given.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'in', 'notIn', 'lt', 'lte', 'gt', 'gte', 'like', 'iLike'] as $operator) {
			$expr->method($operator)->willReturnCallback(static fn ($column, $value): string => "{$operator}({$column},{$value})");
		}

		foreach (['isNull', 'isNotNull'] as $operator) {
			$expr->method($operator)->willReturnCallback(static fn ($column): string => "{$operator}({$column})");
		}

		$expr->method('andX')->willReturnCallback(static fn (...$parts): ICompositeExpression => self::composite(type: 'AND', parts: $parts));
		$expr->method('orX')->willReturnCallback(static fn (...$parts): ICompositeExpression => self::composite(type: 'OR', parts: $parts));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('from')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => ':' . json_encode($value));
		$qb->method('createFunction')->willReturnCallback(static fn ($sql): string => (string)$sql);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * A composite expression that renders as TYPE(part;part).
	 *
	 * @param string       $type  AND or OR.
	 * @param array<mixed> $parts The parts.
	 *
	 * @return ICompositeExpression
	 */
	private static function composite(string $type, array $parts): ICompositeExpression {
		return new class ($type . '(' . implode(';', array_map('strval', $parts)) . ')') implements ICompositeExpression {
			public function __construct(private readonly string $rendered) {
			}

			public function addMultiple(array $parts = []): ICompositeExpression {
				return $this;
			}

			public function add($part): ICompositeExpression {
				return $this;
			}

			public function count(): int {
				return 1;
			}

			public function getType(): string {
				return '';
			}

			public function __toString(): string {
				return $this->rendered;
			}
		};
	}//end composite()

	/**
	 * The conditions the list query of the schema carries for a query.
	 *
	 * @param array<string, mixed> $query The request query.
	 *
	 * @return string[]
	 */
	private function listConditions(array $query): array {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(self::PROPERTIES);
		$this->captured = [];
		$this->handler()->buildFilteredQuery(
			query: array_merge(['_multitenancy_explicit' => true], $query),
			table: new MagicReadTable(schema: $schema, registerId: 3, tableName: 'openregister_table_3_7')
		);

		return $this->captured;
	}//end listConditions()

	/**
	 * The organisation boundary and RBAC are each their own condition, and the
	 * OR tree is one more, so the OR's `isnull` branch cannot admit a row of
	 * another organisation or one RBAC refuses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
	 */
	public function testTheTreeIsANarrowingConditionBesideTheScope(): void {
		$without = $this->listConditions(query: []);
		$with    = $this->listConditions(query: ['_where' => self::TREE]);

		$this->assertContains('ORGANISATION(org-1)', $without);
		$this->assertContains('RBAC(alice)', $without);

		// Everything the list query carried without the tree, it still carries,
		// in the same order: the tree only adds.
		$this->assertSame($without, array_slice($with, 0, count($without)));
		$added = array_values(array_slice($with, count($without)));
		$this->assertCount(1, $added, 'The tree is one condition.');
		$this->assertStringStartsWith('OR(', $added[0]);
		$this->assertStringContainsString('isNull(', $added[0]);
		$this->assertStringNotContainsString('ORGANISATION', $added[0]);
		$this->assertStringNotContainsString('RBAC', $added[0]);
	}//end testTheTreeIsANarrowingConditionBesideTheScope()
}//end class
