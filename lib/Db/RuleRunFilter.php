<?php

/**
 * RuleRunFilter: the verdict and created window a rule-run read is narrowed to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * One verdict (none for null or empty) and a created range (a null bound is open).
 *
 * Shared by RuleRunMapper's per-rule listing, its count and its recent-runs read,
 * so the three narrow the log the same way.
 */
final class RuleRunFilter {

	/**
	 * The verdict, or null/empty for any.
	 *
	 * @var string|null
	 */
	private readonly ?string $verdict;

	/**
	 * Only runs at or after this moment.
	 *
	 * @var DateTime|null
	 */
	private readonly ?DateTime $since;

	/**
	 * Only runs at or before this moment.
	 *
	 * @var DateTime|null
	 */
	private readonly ?DateTime $until;

	/**
	 * Constructor.
	 *
	 * @param string|null   $verdict The verdict, or null/empty for any.
	 * @param DateTime|null $since   Only runs at or after this moment.
	 * @param DateTime|null $until   Only runs at or before this moment.
	 */
	public function __construct(?string $verdict = null, ?DateTime $since = null, ?DateTime $until = null) {
		$this->verdict = $verdict;
		$this->since   = $since;
		$this->until   = $until;
	}//end __construct()

	/**
	 * Narrow a query to this verdict and window.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return void
	 */
	public function applyTo(IQueryBuilder $qb): void {
		if ($this->verdict !== null && $this->verdict !== '') {
			$qb->andWhere($qb->expr()->eq('verdict', $qb->createNamedParameter($this->verdict)));
		}

		if ($this->since !== null) {
			$qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($this->since, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}

		if ($this->until !== null) {
			$qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($this->until, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}
	}//end applyTo()
}//end class
