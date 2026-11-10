<?php

/**
 * FacetResults
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
 * The facets of one facet request as they are computed, with their timings.
 *
 * Holds no access decision: the caller decides which facets are computed and
 * only hands the results here.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class FacetResults {

	/**
	 * The facets computed so far, keyed by field ('@self' holds the metadata facets).
	 *
	 * @var array<string, mixed>
	 */
	public array $facets = [];

	/**
	 * The time each facet took in milliseconds, keyed by field ('@self.<field>' for metadata).
	 *
	 * @var array<string, float>
	 */
	public array $times = [];

	/**
	 * Constructor.
	 *
	 * @param float $startTime When the facet request started, from microtime(true)
	 * @param array $config    The facet configuration keyed by field
	 */
	public function __construct(
		private readonly float $startTime,
		public readonly array $config,
	) {
	}//end __construct()

	/**
	 * Keep the facet of an object field and its title, and time it.
	 *
	 * The title is kept even when the facet type is not supported.
	 *
	 * @param int|string $field    The field the facet is keyed by.
	 * @param array      $computed The facet (null for an unsupported type), its configuration and when it
	 *                             started, as ['facet' => array|null, 'config' => mixed, 'start' => float].
	 *
	 * @return void
	 */
	public function putField(int|string $field, array $computed): void {
		if ($computed['facet'] !== null) {
			$this->facets[$field] = $computed['facet'];
		}

		if (isset($computed['config']['title']) === true) {
			$this->facets[$field]['title'] = $computed['config']['title'];
		}

		$this->times[$field] = self::elapsedMs(since: $computed['start']);
	}//end putField()

	/**
	 * Compute and keep the metadata (@self) facets of the configuration, timing each.
	 *
	 * Nothing happens when the configuration has no '@self' array; otherwise
	 * '@self' is present in the results even when no facet was kept.
	 *
	 * @param callable(int|string, mixed): (array|null) $compute Computes one metadata facet from its field and configuration.
	 *
	 * @return void
	 */
	public function addSelfFacets(callable $compute): void {
		if (is_array($this->config['@self'] ?? null) === false) {
			return;
		}

		$this->facets['@self'] = [];
		foreach ($this->config['@self'] as $field => $config) {
			$start = microtime(true);
			$facet = $compute($field, $config);
			if ($facet !== null) {
				$this->facets['@self'][$field] = $facet;
			}

			$this->times['@self.' . $field] = self::elapsedMs(since: $start);
		}
	}//end addSelfFacets()

	/**
	 * The facets with their timing block.
	 *
	 * @param array $metrics Metrics placed between the total and the per-facet times.
	 * @param array $labels  The label cache statistics.
	 *
	 * @return array The facets, with '_metrics' added.
	 */
	public function withMetrics(array $metrics, array $labels): array {
		$facets = $this->facets;
		$facets['_metrics'] = ['total_ms' => self::elapsedMs(since: $this->startTime)]
			+ $metrics
			+ ['per_facet_ms' => $this->times, 'label_cache' => $labels];

		return $facets;
	}//end withMetrics()

	/**
	 * Milliseconds since a moment, rounded to two decimals.
	 *
	 * @param float $since The moment, from microtime(true).
	 *
	 * @return float The elapsed milliseconds.
	 */
	private static function elapsedMs(float $since): float {
		return round((microtime(true) - $since) * 1000, 2);
	}//end elapsedMs()
}//end class
