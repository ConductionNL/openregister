<?php

namespace OCA\OpenRegister\Service;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

class MySQLJsonService implements IDatabaseJsonService
{
	function filterJson(IQueryBuilder $builder, array $filters): IQueryBuilder
	{
		unset($filters['register'], $filters['schema'], $filters['updated'], $filters['created'], $filters['_queries']);

		foreach($filters as $filter=>$value) {
//			var_dump($value);

			$builder->createNamedParameter(value: "$.$filter", placeHolder: ":path$filter");

			if(is_array($value) === true) {
				$builder->createNamedParameter(value: $value, type: IQueryBuilder::PARAM_STR_ARRAY, placeHolder: ":value$filter");
				$builder
					->andWhere("json_unquote(json_extract(object, :path$filter)) IN (:value$filter)");
				continue;
			}

			$builder->createNamedParameter(value: $value, placeHolder: ":value$filter");
			$builder
				->andWhere("json_extract(object, :path$filter) = :value$filter");
		}
//
//		var_dump($builder->getSQL());
//		var_Dump($builder->getParameters());
		return $builder;
	}

	public function getAggregations(IQueryBuilder $builder, array $fields, int $register, int $schema, array $filters = []): array
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

			$result = $builder->executeQuery();
			$facets[$field] = $result->fetchAll();

			$builder->resetQueryParts();
			$builder->setParameters([]);

		}
		return $facets;
	}
}
