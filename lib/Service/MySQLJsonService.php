<?php

namespace OCA\OpenRegister\Service;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

class MySQLJsonService implements IDatabaseJsonService
{
	function filterJson(IQueryBuilder $builder, array $filters): IQueryBuilder
	{
		unset($filters['register'], $filters['schema'], $filters['updated'], $filters['created'], $filters['_fields']);

		foreach($filters as $filter=>$value) {
			$builder->createNamedParameter(value: $value, placeHolder: ":value$filter");
			$builder->createNamedParameter(value: "$.$filter", placeHolder: ":path$filter");

			$builder
				->andWhere("json_extract(object, :path$filter) = :value$filter");
		}

		return $builder;
	}

	public function getAggregations(IQueryBuilder $builder, array $fields, int $register, int $schema, array $filters = []): array
	{
		$facets = [];

		foreach($fields as $field) {
			$builder->createNamedParameter(value: "$.$field", placeHolder: ":$field");


			$builder
				->selectAlias($builder->createFunction("json_extract(object, ':$field')"), $field)
//				->selectAlias($builder->createFunction("count(*)"), 'count')
				->from('openregister_objects')
				->where(
					$builder->expr()->eq('register', $builder->createNamedParameter($register, IQueryBuilder::PARAM_INT)),
					$builder->expr()->eq('schema', $builder->createNamedParameter($schema, IQueryBuilder::PARAM_INT)),
				)
				->groupBy($field);

			$builder = $this->filterJson($builder, $filters);

//
			var_dump($builder->getSQL());
			var_dump($builder->getParameters());
//die;
			$result = $builder->executeQuery();
			$facets[$field] = $result->fetchAll();

			var_dump($facets[$field]);
		}

		return $facets;
	}
}
