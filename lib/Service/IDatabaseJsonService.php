<?php

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


    /**
     * Searches in the JSON bojects in the objects column for given string.
     *
     * @param IQueryBuilder $builder The query builder, make sure this matches the database platform used.
     * @param string        $search  The search string to search for.
     *
     * @return IQueryBuilder The updated query builder.
     */
    public function searchJson(IQueryBuilder $builder, string $search): IQueryBuilder;


    /**
     * Sorts search results on json fields.
     *
     * @param IQueryBuilder $builder The query builder, make sure this matches the database platform used.
     * @param array         $order   The fields to order on, and the direction to order with.
     *
     * @return IQueryBuilder The updated query builder.
     */
    public function orderJson(IQueryBuilder $builder, array $order): IQueryBuilder;


    /**
     * Generates aggregations (facets) for given fields combined with given filters.
     *
     * @param IQueryBuilder $builder  The query builder, make sure this matches the database platform used.
     * @param array         $fields   The fields to generate aggregations for.
     * @param int           $register The register id to filter.
     * @param int           $schema   The schema id to filter.
     * @param array         $filters  The filters applied to the request.
     * @param string|null   $search   The search string supplied by the request
     *
     * @return array The resulting aggregations
     */
    public function getAggregations(IQueryBuilder $builder, array $fields, int $register, int $schema, array $filters=[], ?string $search=null): array;


}//end interface
