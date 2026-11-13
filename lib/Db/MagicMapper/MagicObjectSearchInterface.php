<?php

/**
 * MagicObjectSearchInterface: Searches, counts and facets objects.
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
 * Searches, counts and facets objects.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicObjectSearchInterface {
	/**
	 * Search objects.
	 *
	 * @param array $query Search query
	 * @param string|null $_activeOrgUuid Organisation UUID
	 * @param bool $_rbac Apply RBAC
	 * @param bool $_multitenancy Apply multitenancy
	 * @param array|null $ids Specific IDs
	 * @param string|null $uses Uses filter
	 *
	 * @return list<ObjectEntity>|int
	 *
	 * @psalm-suppress                              LessSpecificImplementedReturnType
	 */
	public function searchObjects(
		array $query = [], ?string $_activeOrgUuid = null, bool $_rbac = true, bool $_multitenancy = true, ?array $ids = null,
		?string $uses = null
	): array|int;

	/**
	 * Count search objects.
	 *
	 * @param array $query Search query
	 * @param string|null $_activeOrgUuid Organisation UUID
	 * @param bool $_rbac Apply RBAC
	 * @param bool $_multitenancy Apply multitenancy
	 * @param array|null $ids Specific IDs
	 * @param string|null $uses Uses filter
	 *
	 * @return int Object count
	 */
	public function countSearchObjects(
		array $query = [], ?string $_activeOrgUuid = null, bool $_rbac = true, bool $_multitenancy = true, ?array $ids = null,
		?string $uses = null
	): int;

	/**
	 * Optimized paginated search that loads register/schema once and performs both search and count.
	 *
	 * @param array $searchQuery Query for search (with _limit, _offset).
	 * @param array $countQuery Query for count (without pagination).
	 * @param string|null $_activeOrgUuid Active organization UUID.
	 * @param bool $_rbac Whether to apply RBAC.
	 * @param bool $_multitenancy Whether to apply multitenancy.
	 * @param array|null $ids Optional ID filter.
	 * @param string|null $uses Optional uses filter.
	 *
	 * @return array<string, mixed>
	 *
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function searchObjectsPaginated(
		array $searchQuery = [], array $countQuery = [], ?string $_activeOrgUuid = null, bool $_rbac = true, bool $_multitenancy = true,
		?array $ids = null, ?string $uses = null
	): array;

	/**
	 * Count all objects with optional filtering.
	 *
	 * @param array|null $_filters Filters
	 * @param Schema|null $schema Schema filter
	 * @param Register|null $register Register filter
	 *
	 * @return int Object count
	 */
	public function countAll(?array $_filters = null, ?Schema $schema = null, ?Register $register = null): int;

	/**
	 * Count the rows with no organisation in every table of a schema that match the filters.
	 *
	 * Ignores RBAC and any active organisation context.
	 *
	 * @param array  $filters The filters.
	 * @param Schema $schema  The schema.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function countOrganisationless(array $filters, Schema $schema): int;

	/**
	 * Get simple facets.
	 *
	 * Routes to the correct faceting method based on query parameters.
	 *
	 * @param array $query Search query containing register, schema, and _facets configuration.
	 *
	 * @return array Facets data.
	 */
	public function getSimpleFacets(array $query = []): array;

	/**
	 * Get facetable fields from schemas.
	 *
	 * Collects facetable fields from all schemas referenced in the query.
	 *
	 * @param array $baseQuery Base query
	 *
	 * @return array[] Facetable fields
	 *
	 * @psalm-return array<string, array>
	 */
	public function getFacetableFieldsFromSchemas(array $baseQuery = []): array;

	/**
	 * Get simple facets for a register+schema specific table.
	 *
	 * This method retrieves facet data (aggregations) from a dedicated magic mapper table
	 * based on register+schema combination. It supports both metadata facets (@self fields)
	 * and schema property facets.
	 *
	 * @param array $query Search parameters including _facets configuration.
	 * @param Register $register The register context for table selection.
	 * @param Schema $schema The schema for table selection.
	 *
	 * @return array Facet results with buckets.
	 */
	public function getSimpleFacetsFromRegisterSchemaTable(array $query, Register $register, Schema $schema): array;

	/**
	 * Get facets using UNION ALL across multiple register+schema tables.
	 *
	 * This method is optimized for multi-schema faceting by executing ONE query
	 * per facet field using UNION ALL, instead of separate queries per table.
	 * Benchmarks show 2-2.5x speedup for large datasets.
	 *
	 * @param array $query Search parameters including _facets configuration.
	 * @param Register $register The register context.
	 * @param array $schemas Array of Schema objects to include.
	 * @param array $registerSchemaPairs Register/schema pairs.
	 *
	 * @return array Merged facet results.
	 */
	public function getSimpleFacetsUnion(array $query, ?Register $register = null, array $schemas = [], array $registerSchemaPairs = []): array;
}//end interface
