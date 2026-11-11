<?php

/**
 * FacetLabelLookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * One lookup of facet bucket labels for a set of object uuids: the labels
 * found so far and the uuids still unresolved.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class FacetLabelLookup {

	/**
	 * The cache key of the field's labels.
	 *
	 * @var string
	 */
	public string $cacheKey = '';

	/**
	 * When the lookup started, for the logged duration.
	 *
	 * @var float
	 */
	public float $startTime;

	/**
	 * The labels found so far, by uuid.
	 *
	 * @var array<string, string>
	 */
	public array $result = [];

	/**
	 * The uuids without a label yet.
	 *
	 * @var array<int, string>
	 */
	public array $uncached;

	/**
	 * Constructor.
	 *
	 * @param array  $uuids The uuids to label.
	 * @param string $field The facet field.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		public readonly array $uuids,
		public readonly string $field,
	) {
		$this->startTime = microtime(true);
		$this->uncached = $uuids;
	}//end __construct()

	/**
	 * Take the labels a cache holds for this lookup's uuids.
	 *
	 * @param array<string, string> $labels The cached labels, by uuid.
	 *
	 * @return bool Whether every uuid now has a label.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function take(array $labels): bool {
		$this->result = [];
		$this->uncached = [];
		foreach ($this->uuids as $uuid) {
			if (isset($labels[$uuid]) === false) {
				$this->uncached[] = $uuid;
				continue;
			}

			$this->result[$uuid] = $labels[$uuid];
		}

		return $this->uncached === [];
	}//end take()

	/**
	 * Milliseconds since the lookup started.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function elapsedMs(): float {
		return round((microtime(true) - $this->startTime) * 1000, 2);
	}//end elapsedMs()
}//end class
