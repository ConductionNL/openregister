<?php

/**
 * MagicAccessPredicateInterface: The access predicates the mapper applies: RBAC row predicates, organisation boundary and readable-identifier checks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The access predicates the mapper applies: RBAC row predicates, organisation boundary and readable-identifier checks.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicAccessPredicateInterface {
	/**
	 * The row-level access predicate for a raw query over one magic table.
	 *
	 * For a caller that writes its own SQL against the table (the aggregation
	 * fast path) and must count the rows a list read would return, no more.
	 * The predicate is {@see MagicRbacHandler::buildRbacRowPredicateSql()}'s, so
	 * the list and the raw query cannot drift apart. Columns are unqualified:
	 * the query must read the magic table without an alias.
	 *
	 * The predicate is the one for reading the rows.
	 *
	 * @param Schema        $schema   The schema of the table being read.
	 * @param Register|null $register The register of the table, so a metadata column the table could not
	 *                                be given is left out of the predicate. Null keeps every predicate.
	 *
	 * @return string|null Null when no row restriction applies (an admin); otherwise
	 *                     a predicate safe to AND into a WHERE, `1 = 0` for deny-all.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function rbacRowPredicateSql(Schema $schema, ?Register $register = null): ?string;

	/**
	 * Whether the current caller qualifies for at least one rule of an action.
	 *
	 * @param Schema $schema The schema whose rules to consult.
	 * @param string $action The action (default: 'read').
	 *
	 * @return bool True when a rule for the action admits the caller.
	 *
	 * @see MagicRbacHandler::callerQualifiesForAction()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function callerQualifiesForAction(Schema $schema, string $action = 'read'): bool;

	/**
	 * Whether a `public` read rule lifts the organisation filter for the current caller.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the `public` rule may lift the organisation filter.
	 *
	 * @see MagicRbacHandler::publicReadLiftsOrganisationFilter()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-when-inheritfrompublic-is-false-authenticated-users-must-not-qualify-for-public-rules
	 */
	public function publicReadLiftsOrganisationFilter(Schema $schema): bool;

	/**
	 * Whether a default read's organisation boundary carries a branch for the
	 * caller's conditional `crossOrganisation` rules.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when it does.
	 *
	 * @see MagicSearchHandler::organisationBoundaryHasCrossOrganisationBranch()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
	 */
	public function organisationBoundaryHasCrossOrganisationBranch(Schema $schema): bool;

	/**
	 * Whether a default list read waives the organisation boundary for this
	 * caller because RBAC governs the rows instead.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the list would not apply the organisation filter.
	 *
	 * @see MagicSearchHandler::organisationBoundaryWaivedByRbac()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function organisationBoundaryWaivedByRbac(Schema $schema): bool;

	/**
	 * Whether a default list read widens the organisation boundary by the
	 * rows with no organisation, because the caller's grant comes from the
	 * register cascade.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the list's organisation filter also admits org-less rows.
	 *
	 * @see MagicSearchHandler::organisationlessRowsAdmittedByRbac()
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public function organisationlessRowsAdmittedByRbac(Schema $schema): bool;

	/**
	 * Whether a stored row lies inside the caller's organisations.
	 *
	 * Decided on the row's own organisation through the same scope the read
	 * filter renders, but without the read rules: a `public` read rule that
	 * lifts the organisation filter for reading does not widen this answer.
	 * The row's register and schema name the table, for shared master data.
	 *
	 * @param ObjectEntity $row The stored row.
	 *
	 * @return bool True when the caller's organisation scope holds the row.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function callerOrganisationsHold(ObjectEntity $row): bool;

	/**
	 * Which of these UUIDs, all living in one register+schema table, may the caller read?
	 *
	 * The answer comes from the same access-control filter the single-object read
	 * applies (applyAccessControlToQuery: RBAC plus multitenancy, with every rule
	 * that widens or narrows them), so a name lookup can never disagree with
	 * `GET /api/objects/{register}/{schema}/{id}`. Soft-deleted rows are never
	 * readable here. Any failure answers "none": a lookup that cannot establish
	 * read access must not disclose.
	 *
	 * @param int           $registerId The register the table belongs to.
	 * @param int           $schemaId   The schema the table belongs to.
	 * @param array<string> $uuids      UUIDs to test, all expected in that table.
	 *
	 * @return array<int, string> The subset of $uuids the caller may read.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function filterReadableUuids(int $registerId, int $schemaId, array $uuids): array;

	/**
	 * The same filter as filterReadableUuids(), but a failure is told apart from "none".
	 *
	 * A caller that reports completeness (a tree that says which bound stopped
	 * it) must not read "read access could not be established" as "nothing is
	 * readable here": the first is an incomplete answer, the second is a
	 * complete one. Null means the check itself failed (and was logged);
	 * an empty array means it ran and the caller may read none of the uuids.
	 *
	 * @param int           $registerId The register the table belongs to.
	 * @param int           $schemaId   The schema the table belongs to.
	 * @param array<string> $uuids      UUIDs to test, all expected in that table.
	 *
	 * @return array<int, string>|null The readable subset, or null when read access could not be established.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function readableUuidsOrNull(int $registerId, int $schemaId, array $uuids): ?array;
}//end interface
