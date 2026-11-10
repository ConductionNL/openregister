<?php

/**
 * The access rules a related-row subquery needs beyond the related schema's row predicate.
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
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\AliasedRows;
use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeResolverInterface;
use OCA\OpenRegister\Db\MagicMapper\RelatedRowRbacInterface;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * The row predicate says which rows of the related schema may be consulted. It
 * says nothing about which of their FIELDS may be matched on, and it carries no
 * organisation term. This adds both: the property read rule for every field a
 * block names, and the organisation boundary for the related alias.
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
 */
class RelatedRowAccess {

	/**
	 * Decides whether the caller may read a property.
	 *
	 * @var PropertyReadGuard
	 */
	private readonly PropertyReadGuard $guard;

	/**
	 * Constructor.
	 *
	 * @param RelatedRowRbacInterface $rbac   Builds the related schema's row predicate.
	 * @param LoggerInterface         $logger The logger, for the default guard.
	 * @param PropertyReadGuard|null  $guard  The property read guard; defaults to one without a container, which fails closed on a governed schema.
	 */
	public function __construct(private readonly RelatedRowRbacInterface $rbac, LoggerInterface $logger, ?PropertyReadGuard $guard = null) {
		$this->guard = ($guard ?? new PropertyReadGuard(container: null, logger: $logger));
	}//end __construct()

	/**
	 * Whether the caller may read a property of a schema.
	 *
	 * @param Schema $schema   The schema.
	 * @param string $property The property.
	 *
	 * @return bool False when it is withheld, or the rule cannot be evaluated.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	public function mayReadProperty(Schema $schema, string $property): bool {
		return $this->guard->mayRead(schema: $schema, property: $property);
	}//end mayReadProperty()

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
	 * @param IQueryBuilder    $qb     The query, which receives the organisation parameters.
	 * @param RelatedRowSource $source The related schema, its bare table (for the row predicate), the related
	 *                                 row's alias and the decided organisation scope.
	 *
	 * @return string The combined predicate.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function predicate(IQueryBuilder $qb, RelatedRowSource $source): string {
		$rbacPredicate = $this->rbac->buildRbacPredicateForAlias(
			schema: $source->schema,
			rows: new AliasedRows(alias: $source->alias, action: 'read', tableName: $source->tableName)
		);

		$term = $this->organisationTerm(qb: $qb, source: $source);
		if ($term === null) {
			return $rbacPredicate;
		}

		return '(' . $rbacPredicate . ') AND ' . $term;
	}//end predicate()

	/**
	 * The organisation boundary for the related alias, or null when the scope admits every organisation.
	 *
	 * @param IQueryBuilder    $qb     The query, which receives the organisation parameters.
	 * @param RelatedRowSource $source The related row's alias and the decided organisation scope.
	 *
	 * @return string|null The term, ANDed onto the row predicate.
	 */
	private function organisationTerm(IQueryBuilder $qb, RelatedRowSource $source): ?string {
		$mode = ($source->scope['mode'] ?? null);
		if ($mode === OrganisationScopeResolverInterface::SCOPE_ALL) {
			return null;
		}

		if ($mode === OrganisationScopeResolverInterface::SCOPE_NULL_ONLY) {
			return self::organisationColumn(source: $source) . ' IS NULL';
		}

		if (self::namesOrganisations(scope: $source->scope) === false) {
			// SCOPE_NONE, an unknown mode, or "in these organisations" naming none.
			return '1 = 0';
		}

		return $this->membershipTerm(qb: $qb, source: $source);
	}//end organisationTerm()

	/**
	 * Whether the scope is "in these organisations" (with or without NULL) and names at least one.
	 *
	 * @param array<string, mixed> $scope The decided organisation scope.
	 *
	 * @return bool True when the scope restricts to named organisations.
	 */
	private static function namesOrganisations(array $scope): bool {
		$scoped = [OrganisationScopeResolverInterface::SCOPE_IN, OrganisationScopeResolverInterface::SCOPE_IN_OR_NULL];

		return (in_array(($scope['mode'] ?? null), $scoped, true) === true && self::scopedUuids(scope: $scope) !== []);
	}//end namesOrganisations()

	/**
	 * The organisation uuids the scope names, without empties or non-strings.
	 *
	 * @param array<string, mixed> $scope The decided organisation scope.
	 *
	 * @return array<int, string> The uuids.
	 */
	private static function scopedUuids(array $scope): array {
		return array_values(array_filter(
			($scope['uuids'] ?? []),
			static fn ($uuid): bool => is_string($uuid) === true && $uuid !== ''
		));
	}//end scopedUuids()

	/**
	 * The related alias's organisation IN its named organisations, bound as parameters; OR NULL for that mode.
	 *
	 * @param IQueryBuilder    $qb     The query, which receives the organisation parameters.
	 * @param RelatedRowSource $source The related row's alias and an "in these organisations" scope.
	 *
	 * @return string The term.
	 */
	private function membershipTerm(IQueryBuilder $qb, RelatedRowSource $source): string {
		$column       = self::organisationColumn(source: $source);
		$placeholders = [];
		foreach (self::scopedUuids(scope: $source->scope) as $position => $uuid) {
			$name           = sprintf('%s_org%d', $source->alias, $position);
			$placeholders[] = ':' . $name;
			$qb->setParameter($name, $uuid);
		}

		$term = $column . ' IN (' . implode(', ', $placeholders) . ')';
		if (($source->scope['mode'] ?? null) === OrganisationScopeResolverInterface::SCOPE_IN_OR_NULL) {
			$term = '(' . $term . ' OR ' . $column . ' IS NULL)';
		}

		return $term;
	}//end membershipTerm()

	/**
	 * The related alias's organisation column.
	 *
	 * @param RelatedRowSource $source The related row's alias.
	 *
	 * @return string The qualified column.
	 */
	private static function organisationColumn(RelatedRowSource $source): string {
		return $source->alias . '._organisation';
	}//end organisationColumn()

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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
	 */
	public function assertReadable(RelatedRowFilter $filter, Schema $schema): void {
		$fields = array_column($filter->conditions, 'field');
		if ($filter->direction !== RelatedRowFilter::DIRECTION_FORWARD) {
			$fields[] = $filter->foreignKey;
		}

		$properties = $schema->getProperties();
		$explicit = ExplicitReveal::explicitProperties(schema: $schema);
		foreach ($fields as $field) {
			$field = (string)$field;
			// A value shown only through the logged reveal cannot be matched on
			// through a related row either: that would read it without the reveal.
			if (in_array($field, $explicit, true) === true) {
				throw new ExplicitRevealQueryException(
					message: sprintf('\'%s\' is shown only on request, so a related-row filter cannot match on it.', $field)
				);
			}

			if (array_key_exists($field, $properties) === false
				|| $this->guard->mayRead(schema: $schema, property: $field) === false
			) {
				throw new InvalidArgumentException(
					sprintf('\'%s\' is not a property of schema \'%s\' that a related-row filter can match on.', $field, $filter->schema)
				);
			}
		}
	}//end assertReadable()
}//end class
