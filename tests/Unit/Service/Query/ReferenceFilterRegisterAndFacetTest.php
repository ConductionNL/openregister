<?php

/**
 * `_ref` reads a referenced schema in the register its property names, and joins it for a facet.
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
use OCA\OpenRegister\Db\RegisterMapper;
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
 * A reference property naming a `register` reads its referenced schema there
 * (D-4); a register that cannot be found is refused, never swapped for the
 * queried one. A facet over a referenced field joins the referenced row under
 * the same join, soft-delete exclusion and access predicate the filter uses.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses   \OCA\OpenRegister\Service\Query\ReferenceFilterParser
 * @uses   \OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration
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
class ReferenceFilterRegisterAndFacetTest extends TestCase {

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
	 * @param array<string, int>|null      $registers Register id per identifier the register mapper finds; null for no mapper.
	 *
	 * @return RelatedRowQueryApplier The applier.
	 */
	private function loggingApplier(array &$log, array $bySlug, array $tables, bool $readable=true, ?Schema $byId=null, ?array $registers=null): RelatedRowQueryApplier {
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

		$registerMapper = null;
		if ($registers !== null) {
			$registerMapper = $this->createMock(RegisterMapper::class);
			$registerMapper->method('find')->willReturnCallback(
				static function (int|string $id) use (&$log, $registers): Register {
					$log[] = 'findRegister ' . $id;
					if (isset($registers[(string)$id]) === false) {
						throw new RuntimeException('none');
					}

					$register = new Register();
					$register->setId($registers[(string)$id]);
					return $register;
				}
			);
		}

		return new RelatedRowQueryApplier(
			lookups: new RelatedRowLookups(schemaMapper: $schemaMapper, tableHandler: $tableHandler, registerMapper: $registerMapper),
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
		$qb->method('innerJoin')->willReturnCallback(
			static function (string $from, string $join, string $alias, mixed $condition) use (&$log, $qb): IQueryBuilder {
				$log[] = 'innerJoin ' . $from . ' ' . $join . ' ' . $alias . ' ON ' . $condition;
				return $qb;
			}
		);

		return $qb;
	}//end loggingBuilder()

	/**
	 * The request schema with `aanvrager` pointing to persons kept in another register.
	 *
	 * @param string|int $register What the property's `register` names.
	 * @param bool       $list     Whether it holds a list of references.
	 *
	 * @return Schema
	 */
	private function requestWithRegister(string|int $register, bool $list=false): Schema {
		$request = $this->request();
		$config  = ['type' => 'string', 'format' => 'uuid', '$ref' => 'person', 'register' => $register];
		if ($list === true) {
			$config = ['type' => 'array', 'items' => ['type' => 'string', '$ref' => 'person', 'register' => $register]];
		}

		$request->setProperties(['aanvrager' => $config, 'titel' => ['type' => 'string']]);
		return $request;
	}//end requestWithRegister()

	/**
	 * A property naming a register by slug reads the referenced table and scope of that register.
	 *
	 * @return void
	 */
	public function testAReferenceIntoAnotherRegisterReadsThatRegistersTable(): void {
		$log = [];
		$this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person(), registers: ['personen' => 5])->apply(
			target: $this->target(log: $log),
			query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Zuiddrecht']]],
			schema: $this->requestWithRegister(register: 'personen')
		);

		$this->assertContains('findRegister personen', $log);
		$this->assertContains('scope 12 5', $log);
		$this->assertContains('rbac rel0 read openregister_table_5_12', $log);
		$this->assertContains(
			'createFunction EXISTS (SELECT 1 FROM *PREFIX*openregister_table_5_12 rel0 WHERE rel0._uuid = t."aanvrager" AND rel0._deleted IS NULL AND '
			. "((rel0._owner = 'alice') AND rel0._organisation IN (:rel0_org0)) AND rel0.\"woonplaats\" = :rel0_c0)",
			$log
		);
		$this->assertNotContains('scope 12 1', $log);
	}//end testAReferenceIntoAnotherRegisterReadsThatRegistersTable()

	/**
	 * A list reference into another register joins through the list on that register's table.
	 *
	 * @return void
	 */
	public function testAListReferenceIntoAnotherRegisterJoinsThroughTheListThere(): void {
		$log = [];
		$this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person(), registers: ['5' => 5])->apply(
			target: $this->target(log: $log),
			query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Zuiddrecht']]],
			schema: $this->requestWithRegister(register: 5, list: true)
		);

		$this->assertContains('findRegister 5', $log);
		$this->assertContains(
			'createFunction EXISTS (SELECT 1 FROM *PREFIX*openregister_table_5_12 rel0 WHERE JSON_CONTAINS(t."aanvrager", JSON_QUOTE(rel0._uuid))'
			. " AND rel0._deleted IS NULL AND ((rel0._owner = 'alice') AND rel0._organisation IN (:rel0_org0)) AND rel0.\"woonplaats\" = :rel0_c0)",
			$log
		);
	}//end testAListReferenceIntoAnotherRegisterJoinsThroughTheListThere()

	/**
	 * A property naming the queried register itself needs no lookup.
	 *
	 * @return void
	 */
	public function testNamingTheQueriedRegisterNeedsNoLookup(): void {
		$log = [];
		$this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person(), registers: [])->apply(
			target: $this->target(log: $log),
			query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Zuiddrecht']]],
			schema: $this->requestWithRegister(register: '1')
		);

		$this->assertSame([], array_values(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'findRegister'))));
		$this->assertContains('rbac rel0 read openregister_table_1_12', $log);
	}//end testNamingTheQueriedRegisterNeedsNoLookup()

	/**
	 * A register that cannot be found is refused naming it, and the query is left untouched.
	 *
	 * @return void
	 */
	public function testARegisterThatCannotBeFoundIsRefusedNotSwapped(): void {
		$log = [];
		try {
			$this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person(), registers: [])->apply(
				target: $this->target(log: $log),
				query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Zuiddrecht']]],
				schema: $this->requestWithRegister(register: 'weg')
			);
			$this->fail('A reference into a missing register was applied.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame("The register 'weg' that property 'aanvrager' references cannot be found.", $e->getMessage());
		}

		$this->assertNotContains('andWhere', $log);
		$this->assertNotContains('tableName 12', $log);
	}//end testARegisterThatCannotBeFoundIsRefusedNotSwapped()

	/**
	 * Without a register mapper a numeric register is used by id; a slug cannot be and is refused.
	 *
	 * @return void
	 */
	public function testWithoutARegisterLookupOnlyANumericRegisterIsUsed(): void {
		$log = [];
		$this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person())->apply(
			target: $this->target(log: $log),
			query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Zuiddrecht']]],
			schema: $this->requestWithRegister(register: 9)
		);
		$this->assertContains('rbac rel0 read openregister_table_9_12', $log);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("The register 'personen'");
		$other = [];
		$this->loggingApplier(log: $other, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person())->apply(
			target: $this->target(log: $other),
			query: ['_ref' => ['aanvrager' => ['woonplaats' => 'Zuiddrecht']]],
			schema: $this->requestWithRegister(register: 'personen')
		);
	}//end testWithoutARegisterLookupOnlyANumericRegisterIsUsed()

	/**
	 * A facet over a referenced field joins the referenced row under its access predicate and scope.
	 *
	 * @return void
	 */
	public function testAFacetJoinsTheReferencedRowUnderItsOwnAccessPredicate(): void {
		$log = [];
		$expression = $this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person())->joinReferenceForFacet(
			target: $this->target(log: $log),
			schema: $this->request(),
			property: 'aanvrager',
			field: 'woonplaats'
		);

		$this->assertSame('rf0."woonplaats"', $expression);
		$this->assertSame(
			[
				'findBySlug person',
				'mayRead aanvrager',
				'mayRead woonplaats',
				'tableExists 12',
				'tableName 12',
				'scope 12 1',
				'rbac rf0 read openregister_table_1_12',
				'setParameter rf0_org0="org-a"',
				'innerJoin t openregister_table_1_12 rf0 ON rf0._uuid = t."aanvrager" AND rf0._deleted IS NULL AND '
				. "((rf0._owner = 'alice') AND rf0._organisation IN (:rf0_org0))",
			],
			$log
		);
	}//end testAFacetJoinsTheReferencedRowUnderItsOwnAccessPredicate()

	/**
	 * A facet over a list reference into another register joins through the list on that register's table.
	 *
	 * @return void
	 */
	public function testAFacetOverAListReferenceJoinsThroughTheList(): void {
		$log = [];
		$this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person(), registers: ['personen' => 5])->joinReferenceForFacet(
			target: $this->target(log: $log),
			schema: $this->requestWithRegister(register: 'personen', list: true),
			property: 'aanvrager',
			field: 'woonplaats'
		);

		$this->assertSame(
			'innerJoin t openregister_table_5_12 rf0 ON JSON_CONTAINS(t."aanvrager", JSON_QUOTE(rf0._uuid)) AND rf0._deleted IS NULL AND '
			. "((rf0._owner = 'alice') AND rf0._organisation IN (:rf0_org0))",
			end($log)
		);
		$this->assertContains('scope 12 5', $log);
	}//end testAFacetOverAListReferenceJoinsThroughTheList()

	/**
	 * A field the caller may not read omits the facet and joins nothing.
	 *
	 * @return void
	 */
	public function testAFacetOnAnUnreadableFieldIsOmitted(): void {
		$log = [];
		$expression = $this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], readable: false, byId: $this->person())->joinReferenceForFacet(
			target: $this->target(log: $log),
			schema: $this->request(),
			property: 'aanvrager',
			field: 'woonplaats'
		);

		$this->assertNull($expression);
		$this->assertSame([], array_values(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'innerJoin') || str_starts_with($line, 'rbac'))));
	}//end testAFacetOnAnUnreadableFieldIsOmitted()

	/**
	 * A referenced schema without a table yet counts nothing: the query matches no row and nothing is joined.
	 *
	 * @return void
	 */
	public function testAFacetOnAReferencedSchemaWithoutATableCountsNothing(): void {
		$log = [];
		$expression = $this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [12 => false], byId: $this->person())->joinReferenceForFacet(
			target: $this->target(log: $log),
			schema: $this->request(),
			property: 'aanvrager',
			field: 'woonplaats'
		);

		$this->assertSame('NULL', $expression);
		$this->assertSame(['tableExists 12', 'createFunction 1 = 0', 'andWhere'], array_slice($log, -3));
	}//end testAFacetOnAReferencedSchemaWithoutATableCountsNothing()

	/**
	 * A facet takes the filter's names and refuses what the filter refuses, naming the part.
	 *
	 * @return void
	 */
	public function testAFacetRefusesWhatTheFilterRefuses(): void {
		foreach (
			[
				['aanvrager', 'woonplaatz', "the referenced schema has no field 'woonplaatz'"],
				['titel', 'woonplaats', "the property 'titel' is not a reference"],
				['onbekend', 'woonplaats', "the schema has no property 'onbekend'"],
				['aanvrager', 'adres.plaats', 'reaches through more than one reference'],
				['aanvrager:2', 'woonplaats', 'a facet names the property without a suffix'],
			] as [$property, $field, $fragment]
		) {
			$log = [];
			try {
				$this->loggingApplier(log: $log, bySlug: ['person' => [$this->person()]], tables: [], byId: $this->person())->joinReferenceForFacet(
					target: $this->target(log: $log),
					schema: $this->request(),
					property: $property,
					field: $field
				);
				$this->fail('A facet on ' . $property . '.' . $field . ' was joined.');
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString($fragment, $e->getMessage());
				$this->assertStringStartsWith('_facets[_ref][', $e->getMessage());
			}

			$this->assertSame([], array_values(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'innerJoin'))));
		}
	}//end testAFacetRefusesWhatTheFilterRefuses()
}//end class
