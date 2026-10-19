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
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
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
	 * @param PropertyReadGuard|null $propertyGuard Decides whether the caller may read a property a condition names;
	 *                                              defaults to one that fails closed on a governed schema.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicTableHandler $tableHandler,
		private readonly MagicRbacHandler $rbacHandler,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		?PropertyReadGuard $propertyGuard = null,
	) {
		$this->propertyGuard = ($propertyGuard ?? new PropertyReadGuard(container: null, logger: $logger));
	}//end __construct()

	/**
	 * Decides whether the caller may read a property a condition names.
	 *
	 * @var PropertyReadGuard
	 */
	private readonly PropertyReadGuard $propertyGuard;

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
		$engine  = $this->engine();
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

			$this->assertPropertiesReadable(filter: $filter, schema: $schema);

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
				accessPredicate: $this->accessPredicate(
					qb: $qb,
					rbacPredicate: $this->rbacHandler->buildRbacPredicateForAlias(
						schema: $schema,
						alias: $alias,
						action: 'read',
						tableName: $relatedTable
					),
					scope: $organisationScopeFor($schema, $register->getId()),
					alias: $alias
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
	 * The related rows' access predicate: the RBAC row rule AND the organisation boundary.
	 *
	 * 🔴 THE ORGANISATION BOUNDARY IS A SEPARATE TERM FROM THE ROW RULE. The row
	 * predicate carries no `_organisation` term (on a schema with no
	 * authorization block it admits every row), so a subquery that stopped there
	 * matched rows of another organisation: a caller who writes a foreign row's
	 * uuid into their own object could probe that row's fields, and foreign rows
	 * pointing at the caller's object satisfied `_related`. The outer list would
	 * never return those rows, so the subquery must not consult them.
	 *
	 * The SCOPE is decided by the caller of the list path (one decision, shared
	 * with the outer query); this only renders it for the related alias. A mode
	 * it does not know matches nothing.
	 *
	 * @param IQueryBuilder                                  $qb            The query, which receives the organisation parameters.
	 * @param string                                         $rbacPredicate The related schema's row predicate.
	 * @param array{mode: string, uuids: array<int, string>} $scope         The decided organisation scope.
	 * @param string                                         $alias         The related row's alias.
	 *
	 * @return string The combined predicate.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function accessPredicate(IQueryBuilder $qb, string $rbacPredicate, array $scope, string $alias): string {
		$column = $alias . '._organisation';
		$mode   = ($scope['mode'] ?? null);

		if ($mode === MagicOrganizationHandler::SCOPE_ALL) {
			return $rbacPredicate;
		}

		if ($mode === MagicOrganizationHandler::SCOPE_NULL_ONLY) {
			return '(' . $rbacPredicate . ') AND ' . $column . ' IS NULL';
		}

		$uuids = array_values(array_filter(
			($scope['uuids'] ?? []),
			static fn ($uuid): bool => is_string($uuid) === true && $uuid !== ''
		));
		$scoped = [MagicOrganizationHandler::SCOPE_IN, MagicOrganizationHandler::SCOPE_IN_OR_NULL];
		if (in_array($mode, $scoped, true) === false || $uuids === []) {
			// SCOPE_NONE, an unknown mode, or "in these organisations" naming none.
			return '(' . $rbacPredicate . ') AND 1 = 0';
		}

		$placeholders = [];
		foreach ($uuids as $position => $uuid) {
			$name           = sprintf('%s_org%d', $alias, $position);
			$placeholders[] = ':' . $name;
			$qb->setParameter($name, $uuid);
		}

		$term = $column . ' IN (' . implode(', ', $placeholders) . ')';
		if ($mode === MagicOrganizationHandler::SCOPE_IN_OR_NULL) {
			$term = '(' . $term . ' OR ' . $column . ' IS NULL)';
		}

		return '(' . $rbacPredicate . ') AND ' . $term;
	}//end accessPredicate()

	/**
	 * Refuse a block that names a field that is not a readable property of the related schema.
	 *
	 * 🔴 TWO REFUSALS, ONE MESSAGE. A field must be a PROPERTY of the related
	 * schema, not merely a well-formed name: a magic table also has metadata
	 * columns (`_owner`, `_organisation`, `_authorization`, `_status`), and a
	 * `gt`/`lt` on those discloses object-level sharing metadata of readable rows
	 * by binary search, while an unknown name reached the database as an unknown
	 * column (HTTP 500 instead of the 400 the parser answers elsewhere).
	 *
	 * 🔴 A CONDITION IS A BOOLEAN ORACLE ON THE FIELD IT NAMES. The related
	 * schema's ROW predicate decides which rows may be consulted; it says
	 * nothing about which of their fields. Without this a caller who cannot read
	 * `salary` can still binary-search it with `gt`/`lt`. The refusal is worded
	 * like an unknown property on purpose, so a refused field cannot be told
	 * apart from one that does not exist.
	 *
	 * Covers every property of the related schema the SQL reads: each condition
	 * field and, for `_related`, the foreign key the join compares (for
	 * `_referenced` the foreign key is a property of the outer schema, which this
	 * class is not given).
	 *
	 * @param RelatedRowFilter $filter The parsed block.
	 * @param Schema           $schema The related schema.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a named field is not a property of the schema, or is withheld from the caller.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function assertPropertiesReadable(RelatedRowFilter $filter, Schema $schema): void {
		$fields = array_column($filter->conditions, 'field');
		if ($filter->direction !== RelatedRowFilter::DIRECTION_FORWARD) {
			$fields[] = $filter->foreignKey;
		}

		$properties = $schema->getProperties();
		foreach ($fields as $field) {
			$field = (string)$field;
			if (array_key_exists($field, $properties) === false
				|| $this->propertyGuard->mayRead(schema: $schema, property: $field) === false
			) {
				throw new InvalidArgumentException(
					sprintf('\'%s\' is not a property of schema \'%s\' that a related-row filter can match on.', $field, $filter->schema)
				);
			}
		}
	}//end assertPropertiesReadable()

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
