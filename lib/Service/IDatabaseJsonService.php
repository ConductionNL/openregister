<?php
/**
 * OpenRegister IDatabaseJsonService
 *
 * Interface for handling JSON operations in the OpenRegister application.
 *
 * This interface provides methods for:
 * - Filtering JSON data.
 * - Searching JSON data.
 * - Ordering JSON data.
 * - Aggregating JSON data.
 * - Audit trails and data aggregation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;

interface IDatabaseJsonService
{


    /**
     * Filters the JSON objects in the objects column based upon given filters.
     *
     * @param IQueryBuilder $builder The query builder, make sure this matches the database platform used.
     * @param array         $filters The filters to filter on.
     *
     * @return IQueryBuilder The updated query builder.
     */
    public function filterJson(IQueryBuilder $builder, array $filters): IQueryBuilder;


    // end filterJson()


    /**
     * Searches in the JSON objects in the objects column for given string.
     *
     * @param IQueryBuilder $builder The query builder, make sure this matches the database platform used.
     * @param string        $search  The search string to search for.
     *
     * @return IQueryBuilder The updated query builder.
     */
    public function searchJson(IQueryBuilder $builder, string $search): IQueryBuilder;


    // end searchJson()


    /**
     * Sorts search results on JSON fields.
     *
     * @param IQueryBuilder $builder The query builder, make sure this matches the database platform used.
     * @param array         $order   The fields to order on, and the direction to order with.
     *
     * @return IQueryBuilder The updated query builder.
     */
    public function orderJson(IQueryBuilder $builder, array $order): IQueryBuilder;


    // end orderJson()


    /**
     * Generates aggregations (facets) for given fields combined with given filters.
     *
     * @param IQueryBuilder $builder  The query builder, make sure this matches the database platform used.
     * @param array         $fields   The fields to generate aggregations for.
     * @param int           $register The register id to filter.
     * @param int           $schema   The schema id to filter.
     * @param array         $filters  The filters applied to the request.
     * @param string|null   $search   The search string supplied by the request.
     *
     * @return array The resulting aggregations.
     */
    public function getAggregations(IQueryBuilder $builder, array $fields, int $register, int $schema, array $filters=[], ?string $search=null): array;


    // end getAggregations()}//end interface
