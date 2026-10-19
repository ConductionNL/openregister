<?php

/**
 * Applies `_related` filters to a search query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The one place a `_related` filter becomes SQL on a real query.
 *
 * 🔴 THIS IS THE CALLER THE CLAUSE DID NOT HAVE. `RelatedRowFilterParser` and
 * `RelatedRowExistsClause` were both correct and both unreachable, and a filter
 * with no caller is the same shape as no filter: the query runs, it answers the
 * UNFILTERED set, and nothing anywhere says the narrowing was dropped.
 *
 * 🔑 IT REFUSES RATHER THAN DROPS, ALL THE WAY DOWN. The parser already throws
 * on a malformed block. This adds the two refusals only a live lookup can make:
 * a schema nobody can name, and a schema the caller may not read at all. Both
 * end the query. Skipping either would widen the answer, and wider is the
 * direction that discloses.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
class RelatedRowQueryApplier {

	/**
	 * The parser, clause and lookups.
	 *
	 * @param SchemaMapper      $schemaMapper The schema lookup.
	 * @param MagicTableHandler $tableHandler Resolves a register and schema to their table.
	 * @param IDBConnection     $db           The connection, read for its platform.
	 * @param LoggerInterface   $logger       The logger.
	 * @param RelatedRowAccess  $access       The access rules for the related rows (row predicate, property read
	 *                                        rule, organisation boundary).
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicTableHandler $tableHandler,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly RelatedRowAccess $access,
	) {
	}//end __construct()

	/**
	 * Whether the caller may read a property, for a filter that names it (nested-path filters on the outer table).
	 *
	 * @param Schema $schema   The schema the property belongs to.
	 * @param string $property The property name.
	 *
	 * @return bool False when it is withheld.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	public function mayReadProperty(Schema $schema, string $property): bool {
		return $this->access->mayReadProperty(schema: $schema, property: $property);
	}//end mayReadProperty()

	/**
	 * Narrow a query by every `_related` block it carries.
	 *
	 * Does nothing at all when there is no `_related` key, so every existing
	 * call site is unaffected.
	 *
	 * @param IQueryBuilder $qb         The query being built.
	 * @param array<mixed>  $query      The request query.
	 * @param Register      $register   The register the related rows live in.
	 * @param callable      $organisationScopeFor Given the related schema and the register id, the organisation scope
	 *                                            the list path applies to that table: `array{mode: string, uuids: array<int, string>}`.
	 * @param string        $outerAlias The alias of the outer object row.
	 *
	 * @return int How many clauses were applied.
	 *
	 * @throws InvalidArgumentException When a block names a schema that cannot be resolved.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function apply(IQueryBuilder $qb, array $query, Register $register, callable $organisationScopeFor, string $outerAlias = 't'): int {
		$filters = [];
		if (array_key_exists(RelatedRowFilterParser::KEY, $query) === true) {
			$filters = (new RelatedRowFilterParser())->parse($query);
		}

		if (array_key_exists(RelatedRowFilterParser::FORWARD_KEY, $query) === true) {
			$filters = array_merge(
				$filters,
				(new RelatedRowFilterParser(key: RelatedRowFilterParser::FORWARD_KEY))->parse($query)
			);
		}

		if ($filters === []) {
			return 0;
		}

		$clause  = new RelatedRowExistsClause();
		$engine  = $clause->engineFor(db: $this->db, logger: $this->logger);
		$applied = 0;

		foreach (array_values($filters) as $position => $filter) {
			$alias   = sprintf('rel%d', $position);
			$schema  = $this->resolveSchema(name: $filter->schema);
			$relatedTable = $this->tableHandler->getTableNameForRegisterSchema(register: $register, schema: $schema);

			// A schema that has never stored an object has no table yet, so no
			// row can match. Same answer the list path gives for a missing table.
			if ($this->tableHandler->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
				$qb->andWhere($qb->createFunction('1 = 0'));
				$applied++;
				continue;
			}

			$this->access->assertReadable(filter: $filter, schema: $schema);

			$rendered = $clause->render(
				filter: $filter,
				engine: $engine,
				// Raw SQL gets no automatic table prefix; Nextcloud's connection
				// replaces `*PREFIX*` with the instance's (`oc_` by default).
				table: '*PREFIX*'.$relatedTable,
				outerAlias: $outerAlias,
				innerAlias: $alias,
				// 🔴 THE ACCESS PREDICATE FOR THE RELATED SCHEMA, NOT THE OUTER
				// ONE. The two schemas have different authorization blocks, and
				// reusing the outer query's predicate would decide who may read
				// case properties by asking who may read cases.
				accessPredicate: $this->access->predicate(
					qb: $qb,
					schema: $schema,
					alias: $alias,
					tableName: $relatedTable,
					scope: $organisationScopeFor($schema, $register->getId())
				),
				parameterPrefix: $alias,
				storage: RelatedRowExistsClause::STORAGE_COLUMNS
			);

			foreach ($rendered['parameters'] as $name => $value) {
				$qb->setParameter($name, $value);
			}

			$qb->andWhere($qb->createFunction($rendered['sql']));
			$applied++;
		}

		return $applied;
	}//end apply()

	/**
	 * Resolve the schema a block names, or refuse the whole query.
	 *
	 * 🔑 A SCHEMA NOBODY CAN NAME IS A REFUSAL, NOT A SKIP. Dropping the block
	 * would answer the unfiltered set to a narrow question, which is the exact
	 * failure `RelatedRowFilterParser` was shaped against; making the lookup
	 * lenient here would reintroduce it one layer down.
	 *
	 * @param string $name The schema slug or id from the filter.
	 *
	 * @return Schema The schema.
	 *
	 * @throws InvalidArgumentException When it cannot be resolved.
	 */
	private function resolveSchema(string $name): Schema {
		$bySlug = $this->schemaMapper->findBySlug(slug: $name, limit: 2);
		if (count($bySlug) === 1) {
			return $bySlug[0];
		}

		if (count($bySlug) > 1) {
			// Two schemas answering one slug is ambiguous, and picking the first
			// would silently filter against whichever happened to be created
			// first.
			throw new InvalidArgumentException(
				sprintf('More than one schema is called \'%s\', so the related-row filter is ambiguous.', $name)
			);
		}

		if (ctype_digit($name) === true) {
			try {
				return $this->schemaMapper->find(id: (int)$name);
			} catch (\Throwable $e) {
				$this->logger->debug(
					message: '[RelatedRowQueryApplier] No schema with that id',
					context: ['name' => $name, 'error' => $e->getMessage()]
				);
			}
		}

		throw new InvalidArgumentException(
			sprintf('There is no schema called \'%s\' to filter related rows on.', $name)
		);
	}//end resolveSchema()
}//end class
