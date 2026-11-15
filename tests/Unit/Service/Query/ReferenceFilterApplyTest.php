<?php

/**
 * `_ref` blocks run through the related-row applier as forward filters.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\AliasedRows;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Query\PropertyReadGuard;
use OCA\OpenRegister\Service\Query\RelatedRowAccess;
use OCA\OpenRegister\Service\Query\RelatedRowFilterTarget;
use OCA\OpenRegister\Service\Query\RelatedRowLookups;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A `_ref` block is read against the queried schema and applied as the forward
 * EXISTS clause a `_referenced` block makes, under the referenced schema's own
 * access predicate; a block that cannot be read is refused, never dropped.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses   \OCA\OpenRegister\Service\Query\ReferenceFilterParser
 * @uses   \OCA\OpenRegister\Service\Query\ReferenceFilterDeclaration
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowFilterParser
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowFilter
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowLookups
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowFilterTarget
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowApplyRun
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowSource
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowSqlTarget
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowInnerRow
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowSubquery
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
class ReferenceFilterApplyTest extends TestCase {

	/**
	 * The person schema a request's `aanvrager` points to.
	 *
	 * @return Schema
	 */
	private function person(): Schema {
		$person = new Schema();
		$person->setId(12);
		$person->setProperties(['woonplaats' => ['type' => 'string'], 'naam' => ['type' => 'string']]);

		return $person;
	}//end person()

	/**
	 * The request schema being queried.
	 *
	 * @return Schema
	 */
	private function request(): Schema {
		$request = new Schema();
		$request->setId(7);
		$request->setProperties(
			[
				'aanvrager' => ['type' => 'string', 'format' => 'uuid', '$ref' => '#/components/schemas/person'],
				'titel' => ['type' => 'string'],
			]
		);

		return $request;
	}//end request()

	/**
	 * A target on the logging builder, with an organisation scope that logs.
	 *
	 * @param array<int, string> $log The shared call log.
	 *
	 * @return RelatedRowFilterTarget
	 */
	private function target(array &$log): RelatedRowFilterTarget {
		$register = new Register();
		$register->setId(1);

		return new RelatedRowFilterTarget(
			qb: $this->loggingBuilder(log: $log),
			register: $register,
			organisationScopeFor: static function (Schema $schema, int $registerId) use (&$log): array {
				$log[] = 'scope ' . $schema->getId() . ' ' . $registerId;
				return ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']];
			}
		);
	}//end target()

	/**
	 * An applier that logs every collaborator call, in order, into $log.
	 *
	 * @param array<int, string>           $log      The shared call log.
	 * @param array<string, array<Schema>> $bySlug   What findBySlug returns per slug.
	 * @param array<int, bool>             $tables   Whether the table exists, per schema id.
	 * @param bool                         $readable What the property guard answers.
	 * @param Schema|null                  $byId     What find(id) returns; null throws.
	 *
	 * @return RelatedRowQueryApplier The applier.
	 */
	private function loggingApplier(array &$log, array $bySlug, array $tables, bool $readable=true, ?Schema $byId=null): RelatedRowQueryApplier {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findBySlug')->willReturnCallback(
			static function (string $slug) use (&$log, $bySlug): array {
				$log[] = 'findBySlug ' . $slug;
				return ($bySlug[$slug] ?? []);
			}
		);
		$schemaMapper->method('find')->willReturnCallback(
			static function (int|string $id) use (&$log, $byId): Schema {
				$log[] = 'find ' . $id;
				if ($byId === null) {
					throw new RuntimeException('none');
				}

				return $byId;
			}
		);

		$tableHandler = $this->createMock(MagicTableHandler::class);
		$tableHandler->method('getTableNameForRegisterSchema')->willReturnCallback(
			static function (Register $register, Schema $schema) use (&$log): string {
				$log[] = 'tableName ' . $schema->getId();
				return 'openregister_table_' . $register->getId() . '_' . $schema->getId();
			}
		);
		$tableHandler->method('existsTableForRegisterSchema')->willReturnCallback(
			static function (Register $register, Schema $schema) use (&$log, $tables): bool {
				$log[] = 'tableExists ' . $schema->getId();
				return ($tables[$schema->getId()] ?? true);
			}
		);

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('buildRbacPredicateForAlias')->willReturnCallback(
			static function (Schema $schema, AliasedRows $rows) use (&$log): string {
				$log[] = 'rbac ' . $rows->alias . ' ' . $rows->action . ' ' . $rows->tableName;
				return $rows->alias . '._owner = \'alice\'';
			}
		);

		$guard = $this->createMock(PropertyReadGuard::class);
		$guard->method('mayRead')->willReturnCallback(
			static function (Schema $schema, string $property) use (&$log, $readable): bool {
				$log[] = 'mayRead ' . $property;
				return $readable;
			}
		);

		return new RelatedRowQueryApplier(
			lookups: new RelatedRowLookups(schemaMapper: $schemaMapper, tableHandler: $tableHandler),
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			access: new RelatedRowAccess(rbac: $rbac, logger: new NullLogger(), guard: $guard)
		);
	}//end loggingApplier()

	/**
	 * A query builder that logs every call, in order, into $log.
	 *
	 * @param array<int, string> $log The shared call log.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function loggingBuilder(array &$log): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnCallback(
			static function (string $text) use (&$log): string {
				$log[] = 'createFunction ' . $text;
				return $text;
			}
		);
		$qb->method('setParameter')->willReturnCallback(
			static function (string $name, mixed $value) use (&$log, $qb): IQueryBuilder {
				$log[] = 'setParameter ' . $name . '=' . json_encode($value);
				return $qb;
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			static function (mixed $where) use (&$log, $qb): IQueryBuilder {
				$log[] = 'andWhere';
				return $qb;
			}
		);

		return $qb;
	}//end loggingBuilder()

	/**
	 * `_ref[aanvrager][woonplaats]` narrows by the referenced person's field, joined on the request's own property.
	 *
	 * @return void
	 */
	public function testARefBlockRunsAsTheForwardClauseOnTheReferencedSchema(): void {
		$log     = [];
		$applier = $this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person());

		$applied = $applier->apply(
			target: $this->target(log: $log),
			query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Zuiddrecht']]],
			schema: $this->request()
		);

		$this->assertSame(1, $applied);
		$this->assertSame(
			[
				'findBySlug person',
				'findBySlug 12',
				'find 12',
				'tableName 12',
				'tableExists 12',
				'mayRead woonplaats',
				'scope 12 1',
				'rbac rel0 read openregister_table_1_12',
				'setParameter rel0_org0="org-a"',
				'setParameter rel0_c0="Zuiddrecht"',
				'createFunction EXISTS (SELECT 1 FROM *PREFIX*openregister_table_1_12 rel0 WHERE rel0._uuid = t."aanvrager" AND rel0._deleted IS NULL AND '
				. "((rel0._owner = 'alice') AND rel0._organisation IN (:rel0_org0)) AND rel0.\"woonplaats\" = :rel0_c0)",
				'andWhere',
			],
			$log
		);
	}//end testARefBlockRunsAsTheForwardClauseOnTheReferencedSchema()

	/**
	 * A `_ref` block and the equivalent `_referenced` block render the same clause.
	 *
	 * @return void
	 */
	public function testARefBlockAndItsReferencedTwinRenderTheSameClause(): void {
		$refLog = [];
		$this->loggingApplier(log: $refLog, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person())->apply(
			target: $this->target(log: $refLog),
			query: ['_ref' => ['aanvrager' => ['woonplaats' => ['in' => ['Zuiddrecht', 'Oosterhout']]]]],
			schema: $this->request()
		);
		$twinLog = [];
		$this->loggingApplier(log: $twinLog, bySlug: [], tables: [], byId: $this->person())->apply(
			target: $this->target(log: $twinLog),
			query: ['_referenced' => ['12' => ['aanvrager' => ['woonplaats' => ['in' => ['Zuiddrecht', 'Oosterhout']]]]]]
		);

		$clauses = static fn (array $log): array => array_values(
			array_filter($log, static fn (string $line): bool => str_starts_with($line, 'createFunction') || str_starts_with($line, 'setParameter'))
		);
		$this->assertNotSame([], $clauses($refLog));
		$this->assertSame($clauses($twinLog), $clauses($refLog));
	}//end testARefBlockAndItsReferencedTwinRenderTheSameClause()

	/**
	 * Without the queried schema a `_ref` block cannot be read, so the query is refused untouched.
	 *
	 * @return void
	 */
	public function testARefBlockWithoutTheQueriedSchemaIsRefused(): void {
		$log = [];
		$applier = $this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: []);

		try {
			$applier->apply(target: $this->target(log: $log), query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Z']]]);
			$this->fail('A _ref block without a schema was applied.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('_ref filters need the schema being queried; narrow the query to one schema.', $e->getMessage());
		}

		$this->assertNotContains('andWhere', $log);
	}//end testARefBlockWithoutTheQueriedSchemaIsRefused()

	/**
	 * A property that is not a reference, or a referenced schema nobody can find, is refused untouched.
	 *
	 * @return void
	 */
	public function testAnUnreadableRefBlockIsRefusedNotDropped(): void {
		foreach (
			[
				[['_ref' => ['titel' => ['woonplaats' => 'Z']]], "the property 'titel' is not a reference"],
				[['_ref' => ['aanvrager' => ['woonplaats' => 'Z']]], 'cannot be found'],
			] as [$query, $fragment]
		) {
			$log = [];
			try {
				$this->loggingApplier(log: $log, bySlug: [], tables: [])->apply(target: $this->target(log: $log), query: $query, schema: $this->request());
				$this->fail('An unreadable _ref block was applied.');
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString($fragment, $e->getMessage());
			}

			$this->assertNotContains('andWhere', $log);
		}
	}//end testAnUnreadableRefBlockIsRefusedNotDropped()

	/**
	 * A property holding a list of references is refused, not answered with an equality join that matches nothing.
	 *
	 * @return void
	 */
	public function testAListReferenceIsRefused(): void {
		$request = $this->request();
		$request->setProperties(['betrokkenen' => ['type' => 'array', 'items' => ['type' => 'string', '$ref' => 'person']]]);
		$log = [];

		try {
			$this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person())->apply(
				target: $this->target(log: $log),
				query: ['_ref' => ['betrokkenen' => ['woonplaats' => 'Z']]],
				schema: $request
			);
			$this->fail('A list reference was applied.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(
				'_ref[betrokkenen]: filtering through a list of references is not supported yet; only a property holding one reference can be filtered through.',
				$e->getMessage()
			);
		}

		$this->assertNotContains('andWhere', $log);
	}//end testAListReferenceIsRefused()

	/**
	 * A uuid `$ref` is found by uuid when it is neither a slug nor an id.
	 *
	 * @return void
	 */
	public function testAUuidReferenceIsFoundByUuid(): void {
		$request = $this->request();
		$request->setProperties(['aanvrager' => ['type' => 'string', '$ref' => '5b1f3c1e-0000-4000-8000-0000000000aa']]);
		$log = [];

		$applied = $this->loggingApplier(log: $log, bySlug: [], tables: [], byId: $this->person())->apply(
			target: $this->target(log: $log),
			query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Z']]],
			schema: $request
		);

		$this->assertSame(1, $applied);
		$this->assertContains('find 5b1f3c1e-0000-4000-8000-0000000000aa', $log);
	}//end testAUuidReferenceIsFoundByUuid()
}//end class
