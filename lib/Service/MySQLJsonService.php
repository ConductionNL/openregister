<?php

namespace OCA\OpenRegister\Service;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

class MySQLJsonService implements IDatabaseJsonService
{
	/**
	 * @inheritDoc
	 */
	public function orderJson(IQueryBuilder $builder, array $order = []): IQueryBuilder
	{

		foreach($order as $item=>$direction) {
			$builder->createNamedParameter(value: "$.$item", placeHolder: ":path$item");
			$builder->createNamedParameter(value: $direction, placeHolder: ":direction$item");

			$builder->orderBy($builder->createFunction("json_unquote(json_extract(object, :path$item))"),$direction);
		}

		return $builder;
	}

	/**
	 * @inheritDoc
	 */
	public function searchJson(IQueryBuilder $builder, ?string $search = null): IQueryBuilder
	{
		if ($search !== null) {
			$builder->createNamedParameter(value: "%$search%", placeHolder: ':search');
			$builder->andWhere("JSON_SEARCH(LOWER(object), 'one', LOWER(:search)) IS NOT NULL");
		}

		return $builder;
	}

	/**
	 * Add complex filters to the filter set.
	 *
	 * @param IQueryBuilder $builder The query builder
	 * @param string $filter The filtered field.
	 * @param array $values The values to filter on.
	 *
	 * @return IQueryBuilder The updated query builder.
	 */
	private function jsonFilterArray(IQueryBuilder $builder, string $filter, array $values): IQueryBuilder
	{
		foreach ($values as $key=>$value) {
			switch ($key) {
				case 'after':
					$builder->createNamedParameter(value: $value, type: IQueryBuilder::PARAM_STR, placeHolder: ":value{$filter}after");
					$builder
						->andWhere("json_unquote(json_extract(object, :path$filter)) >= (:value{$filter}after)");
					break;
				case 'before':
					$builder->createNamedParameter(value: $value, type: IQueryBuilder::PARAM_STR, placeHolder: ":value${filter}before");
					$builder
						->andWhere("json_unquote(json_extract(object, :path$filter)) <= (:value{$filter}before)");
					break;
				default:
					$builder->createNamedParameter(value: $value, type: IQueryBuilder::PARAM_STR_ARRAY, placeHolder: ":value$filter");
					$builder
						->andWhere("json_unquote(json_extract(object, :path$filter)) IN (:value$filter)");
					break;

			}
		}

		return $builder;
	}

	/**
	 * Build a string to search multiple values in an array.
	 *
	 * @param array $values The values to search for.
	 * @param string $filter The field to filter on.
	 * @param IQueryBuilder $builder The query builder.
	 *
	 * @return string The resulting query string.
	 */
	private function getMultipleContains (array $values, string $filter, IQueryBuilder $builder): string
	{
		$orString = '';
		foreach($values as $key=>$value)
		{
			$builder->createNamedParameter(value: $value, type: IQueryBuilder::PARAM_STR, placeHolder: ":value$filter$key");
			$orString .= " OR json_contains(object, json_quote(:value$filter$key), :path$filter)";
		}

		return $orString;
	}

	/**
	 * @inheritDoc
	 */
	public function filterJson(IQueryBuilder $builder, array $filters): IQueryBuilder
	{
		unset($filters['register'], $filters['schema'], $filters['updated'], $filters['created'], $filters['_queries']);

		foreach($filters as $filter=>$value) {

			$builder->createNamedParameter(value: "$.$filter", placeHolder: ":path$filter");

			if(is_array($value) === true && array_is_list($value) === false) {
				$builder = $this->jsonFilterArray(builder: $builder, filter: $filter, values: $value);
				continue;
			} else if (is_array($value) === true) {

				$builder->createNamedParameter(value: $value, type: IQueryBuilder::PARAM_STR_ARRAY, placeHolder: ":value$filter");
				$builder
					->andWhere("(json_unquote(json_extract(object, :path$filter)) IN (:value$filter))". $this->getMultipleContains($value, $filter, $builder));
				continue;
			}

			$builder->createNamedParameter(value: $value, placeHolder: ":value$filter");
			$builder
				->andWhere("json_extract(object, :path$filter) = :value$filter OR json_contains(object, json_quote(:value$filter), :path$filter)");
		}

		return $builder;
	}

	/**
	 * @inheritDoc
	 */
	public function getAggregations(IQueryBuilder $builder, array $fields, int $register, int $schema, array $filters = [], ?string $search = null): array
	{
		$facets = [];

		foreach($fields as $field) {
			$builder->createNamedParameter(value: "$.$field", placeHolder: ":$field");


			$builder
				->selectAlias($builder->createFunction("json_unquote(json_extract(object, :$field))"), '_id')
				->selectAlias($builder->createFunction("count(*)"), 'count')
				->from('openregister_objects')
				->where(
					$builder->expr()->eq('register', $builder->createNamedParameter($register, IQueryBuilder::PARAM_INT)),
					$builder->expr()->eq('schema', $builder->createNamedParameter($schema, IQueryBuilder::PARAM_INT)),
				)
				->groupBy('_id');

			$builder = $this->filterJson($builder, $filters);
			$builder = $this->searchJson($builder, $search);

			$result = $builder->executeQuery();
			$facets[$field] = $result->fetchAll();

			$builder->resetQueryParts();
			$builder->setParameters([]);

		}
		return $facets;
	}
}
