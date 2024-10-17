<?php

namespace OCA\OpenRegister\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;

interface IDatabaseJsonService
{
	public function filterJson(IQueryBuilder $builder, array $filters): IQueryBuilder;
	public function getAggregations(IQueryBuilder $builder, array $fields, int $register, int $schema, array $filters = []): array;
}
