<?php

/**
 * AuditStatisticsScope: which audit rows a statistic covers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The register and schema a statistic covers, and the windows that narrow it.
 *
 * @spec exclude audit statistics plumbing moved out of AuditTrailMapper, not a capability of its own
 */
final class AuditStatisticsScope {

	/**
	 * The number of hours to look back.
	 *
	 * @var integer|null
	 */
	public ?int $hours = 24;

	/**
	 * The maximum number of results.
	 *
	 * @var integer|null
	 */
	public ?int $limit = 10;

	/**
	 * The first day of a date range.
	 *
	 * @var DateTime|null
	 */
	public ?DateTime $from = null;

	/**
	 * The last day of a date range.
	 *
	 * @var DateTime|null
	 */
	public ?DateTime $till = null;

	/**
	 * Register/schema combinations to leave out: [['register' => id, 'schema' => id], ...].
	 *
	 * @var array<int, array<string, int>>
	 */
	public array $exclude = [];

	/**
	 * Constructor.
	 *
	 * @param int|null $registerId The register, null for all.
	 * @param int|null $schemaId   The schema, null for all.
	 */
	public function __construct(
		public readonly ?int $registerId = null,
		public readonly ?int $schemaId = null,
	) {
	}//end __construct()

	/**
	 * Narrow a query to the register and schema; those columns are
	 * VARCHAR(255) holding ids as strings.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return void
	 */
	public function narrow(IQueryBuilder $qb): void {
		foreach (['register' => $this->registerId, 'schema' => $this->schemaId] as $column => $id) {
			if ($id !== null) {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter((string) $id, IQueryBuilder::PARAM_STR)));
			}
		}
	}//end narrow()

	/**
	 * The condition that a row was created within the last $hours hours.
	 *
	 * @param IQueryBuilder $qb The query the condition is for.
	 *
	 * @return string The condition.
	 */
	public function createdInWindow(IQueryBuilder $qb): string {
		$since = (new DateTime())->modify("-{$this->hours} hours")->format('Y-m-d H:i:s');

		return $qb->expr()->gte('created', $qb->createNamedParameter($since, IQueryBuilder::PARAM_STR));
	}//end createdInWindow()
}//end class
