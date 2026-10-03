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
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

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
	 * @param MagicRbacHandler  $rbacHandler  Builds the access predicate for the related rows.
	 * @param IDBConnection     $db           The connection, read for its platform.
	 * @param LoggerInterface   $logger       The logger.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicTableHandler $tableHandler,
		private readonly MagicRbacHandler $rbacHandler,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Narrow a query by every `_related` block it carries.
	 *
	 * Does nothing at all when there is no `_related` key, so every existing
	 * call site is unaffected.
	 *
	 * @param IQueryBuilder $qb         The query being built.
	 * @param array<mixed>  $query      The request query.
	 * @param Register      $register   The register the related rows live in.
	 * @param string        $outerAlias The alias of the outer object row.
	 *
	 * @return int How many clauses were applied.
	 *
	 * @throws InvalidArgumentException When a block names a schema that cannot be resolved.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function apply(IQueryBuilder $qb, array $query, Register $register, string $outerAlias = 't'): int {
		if (array_key_exists(RelatedRowFilterParser::KEY, $query) === false) {
			return 0;
		}

		$filters = (new RelatedRowFilterParser())->parse($query);
		$clause  = new RelatedRowExistsClause();
		$engine  = $this->engine();
		$applied = 0;

		foreach (array_values($filters) as $position => $filter) {
			$alias   = sprintf('rel%d', $position);
			$schema  = $this->resolveSchema(name: $filter->schema);
			$rendered = $clause->render(
				filter: $filter,
				engine: $engine,
				table: $this->tableHandler->getTableNameForRegisterSchema(register: $register, schema: $schema),
				outerAlias: $outerAlias,
				innerAlias: $alias,
				// 🔴 THE ACCESS PREDICATE FOR THE RELATED SCHEMA, NOT THE OUTER
				// ONE. The two schemas have different authorization blocks, and
				// reusing the outer query's predicate would decide who may read
				// case properties by asking who may read cases.
				accessPredicate: $this->rbacHandler->buildRbacPredicateForAlias(
					schema: $schema,
					alias: $alias,
					action: 'read'
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

	/**
	 * Which engine the SQL must be written for.
	 *
	 * Read off the LIVE CONNECTION rather than configured separately, because a
	 * second source of truth for the engine is a second thing that can be wrong
	 * about it, and being wrong would surface as a syntax error in production
	 * and nowhere else.
	 *
	 * The detection matches `MagicRbacHandler::isPostgres()` deliberately,
	 * including its fallback: when the platform cannot be read, both default to
	 * MariaDB syntax. Two different guesses would put MariaDB JSON functions
	 * and Postgres operators in the same statement.
	 *
	 * @return string The engine.
	 */
	private function engine(): string {
		try {
			$platform = $this->db->getDatabasePlatform();
			if (stripos(get_debug_type($platform), 'PostgreSQL') !== false) {
				return RelatedRowExistsClause::ENGINE_POSTGRES;
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[RelatedRowQueryApplier] Could not read the database platform; defaulting to MariaDB syntax',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
		}

		return RelatedRowExistsClause::ENGINE_MARIADB;
	}//end engine()
}//end class
