<?php

/**
 * RuleRunMapperInterface: the part of RuleRunMapper that the rules controller uses, and its default page size.
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

/**
 * The methods of RuleRunMapper that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface RuleRunMapperInterface {

	/**
	 * How many rows one run-log read returns unless the caller asks for fewer.
	 *
	 * RuleRunMapper inherits it, so RuleRunMapper::DEFAULT_LIMIT still resolves.
	 *
	 * @var integer
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * One rule's runs, newest first, optionally narrowed.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string|null $verdict Narrow to one verdict.
	 * @param DateTime|null $since Only runs at or after this moment.
	 * @param DateTime|null $until Only runs at or before this moment.
	 * @param int $limit How many rows to return, capped at MAX_LIMIT.
	 * @param int $offset Where to start.
	 *
	 * @return array<int, RuleRun> The rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function findByRule(
		string $ruleId,
		?string $verdict = null,
		?DateTime $since = null,
		?DateTime $until = null,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = 0,
	): array;

	/**
	 * How many runs one rule has in the log, under the same filters.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string|null $verdict Narrow to one verdict.
	 * @param DateTime|null $since Only runs at or after this moment.
	 * @param DateTime|null $until Only runs at or before this moment.
	 *
	 * @return int The number of matching rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function countByRule(
		string $ruleId,
		?string $verdict = null,
		?DateTime $since = null,
		?DateTime $until = null,
	): int;
}//end interface
