<?php

/**
 * ActivityRowFilter — the Tier-2 `type` / `actor` / `after` narrowing of the
 * NC Activity rows an object's marker matched.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * Which Activity rows a caller's filters admit. An empty `type` or `actor`
 * and an `after` of 0 or less admit every row.
 *
 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
 */
final class ActivityRowFilter {

	/**
	 * Constructor.
	 *
	 * @param string $type  The type filter ('' for none).
	 * @param string $actor The actor filter ('' for none).
	 * @param int    $after The lower time bound (0 or less for none).
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $actor,
		public readonly int $after,
	) {
	}//end __construct()

	/**
	 * The filter a caller's `type` / `actor` / `after` values describe.
	 *
	 * @param array $filters The caller's filters.
	 *
	 * @return self
	 */
	public static function fromFilters(array $filters): self {
		$after = 0;
		if (isset($filters['after']) === true) {
			$after = (int)$filters['after'];
		}

		return new self(
			type: self::filterString(filters: $filters, key: 'type'),
			actor: self::filterString(filters: $filters, key: 'actor'),
			after: $after
		);
	}//end fromFilters()

	/**
	 * Whether this filter narrows nothing.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->type === '' && $this->actor === '' && $this->after === 0;
	}//end isEmpty()

	/**
	 * Whether a row passes all three filters.
	 *
	 * @param array $row The activity row.
	 *
	 * @return bool
	 */
	public function admits(array $row): bool {
		return $this->admitsType(row: $row) && $this->admitsActor(row: $row) && $this->admitsTimestamp(row: $row);
	}//end admits()

	/**
	 * Whether the row passes the type filter (always, when no type is set).
	 *
	 * @param array $row The activity row.
	 *
	 * @return bool
	 */
	private function admitsType(array $row): bool {
		return $this->type === '' || (string)($row['type'] ?? '') === $this->type;
	}//end admitsType()

	/**
	 * Whether the row passes the actor filter (always, when no actor is set).
	 *
	 * @param array $row The activity row.
	 *
	 * @return bool
	 */
	private function admitsActor(array $row): bool {
		return $this->actor === '' || (string)($row['affecteduser'] ?? '') === $this->actor;
	}//end admitsActor()

	/**
	 * Whether the row passes the after filter (always, when no positive cutoff is set).
	 *
	 * @param array $row The activity row.
	 *
	 * @return bool
	 */
	private function admitsTimestamp(array $row): bool {
		return $this->after <= 0 || (int)($row['timestamp'] ?? 0) >= $this->after;
	}//end admitsTimestamp()

	/**
	 * A string filter value, or '' when it is not set.
	 *
	 * @param array  $filters The filters.
	 * @param string $key     The filter key.
	 *
	 * @return string
	 */
	private static function filterString(array $filters, string $key): string {
		if (isset($filters[$key]) === true) {
			return (string)$filters[$key];
		}

		return '';
	}//end filterString()
}//end class
