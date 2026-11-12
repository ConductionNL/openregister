<?php

/**
 * StatisticsPairFilter: the register, schema and exclusion filters of a statistics count.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * Which register-schema pairs MagicStatisticsHandler::getStatistics() counts.
 *
 * A null id covers every register (or schema); an array covers the ids in it,
 * compared strictly; an int covers that one id. A pair listed in the exclusions
 * (as both `register` and `schema`) is never covered.
 *
 * @spec exclude shared statistics plumbing, not a capability of its own
 */
final class StatisticsPairFilter {

	/**
	 * The registers to cover.
	 *
	 * @var int|array|null
	 */
	private readonly int|array|null $registerId;

	/**
	 * The schemas to cover.
	 *
	 * @var int|array|null
	 */
	private readonly int|array|null $schemaId;

	/**
	 * Constructor.
	 *
	 * @param array{register: int|array|null, schema: int|array|null} $wanted  The registers and the schemas to cover.
	 * @param array                                                     $exclude Pairs never to cover, each with `register` and `schema`.
	 */
	public function __construct(
		array $wanted,
		private readonly array $exclude,
	) {
		$this->registerId = $wanted['register'];
		$this->schemaId = $wanted['schema'];
	}//end __construct()

	/**
	 * Whether a pair passes the filters.
	 *
	 * @param array{0: int, 1: int} $ids The register id and the schema id.
	 *
	 * @return bool True when the pair is counted.
	 *
	 * @spec exclude shared statistics plumbing, not a capability of its own
	 */
	public function matches(array $ids): bool {
		if ($this->idMatches(wanted: $this->registerId, id: $ids[0]) === false) {
			return false;
		}

		if ($this->idMatches(wanted: $this->schemaId, id: $ids[1]) === false) {
			return false;
		}

		return $this->isExcluded(ids: $ids) === false;
	}//end matches()

	/**
	 * Whether an id passes one id filter.
	 *
	 * @param int|array|null $wanted The filter: null for any, an array of ids, or one id.
	 * @param int            $id     The id of the pair.
	 *
	 * @return bool True when the id is covered.
	 */
	private function idMatches(int|array|null $wanted, int $id): bool {
		if ($wanted === null) {
			return true;
		}

		if (is_array($wanted) === true) {
			return in_array($id, $wanted, true);
		}

		return $id === $wanted;
	}//end idMatches()

	/**
	 * Whether a pair is listed in the exclusions.
	 *
	 * @param array{0: int, 1: int} $ids The register id and the schema id.
	 *
	 * @return bool True when an exclusion names both ids.
	 */
	private function isExcluded(array $ids): bool {
		foreach ($this->exclude as $ex) {
			if (isset($ex['register'], $ex['schema']) === true
				&& (int)$ex['register'] === $ids[0]
				&& (int)$ex['schema'] === $ids[1]
			) {
				return true;
			}
		}

		return false;
	}//end isExcluded()
}//end class
