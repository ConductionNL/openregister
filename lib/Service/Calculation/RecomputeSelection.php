<?php

/**
 * Which objects of a schema a recompute re-derives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Every object, the objects with the given ids, or the objects matching the object list filters.
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */
final readonly class RecomputeSelection {
	/**
	 * Name the selection; use the named constructors.
	 *
	 * @param list<string>|null    $ids     The object ids or uuids, or null when the selection is not by id.
	 * @param array<string, mixed> $filters The object list filters.
	 */
	private function __construct(
		public ?array $ids,
		public array $filters,
	) {
	}//end __construct()

	/**
	 * Every object of the schema.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public static function all(): self {
		return new self(ids: null, filters: []);
	}//end all()

	/**
	 * The objects with these ids or uuids.
	 *
	 * @param list<string> $ids The ids or uuids.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public static function byIds(array $ids): self {
		return new self(ids: array_values(array_map('strval', $ids)), filters: []);
	}//end byIds()

	/**
	 * The objects matching the object list filters.
	 *
	 * @param array<string, mixed> $filters The filters, as the object list takes them.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public static function byFilters(array $filters): self {
		return new self(ids: null, filters: $filters);
	}//end byFilters()

	/**
	 * Whether the selection holds an object, by its id or uuid.
	 *
	 * @param string $id   The object's id.
	 * @param string $uuid The object's uuid.
	 *
	 * @return bool True when the selection is not by id, or names the object.
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public function holds(string $id, string $uuid): bool {
		if ($this->ids === null) {
			return true;
		}

		return in_array($id, $this->ids, true) === true || in_array($uuid, $this->ids, true) === true;
	}//end holds()
}//end class
