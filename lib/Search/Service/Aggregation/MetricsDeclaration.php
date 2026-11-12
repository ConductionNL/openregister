<?php

/**
 * A declared `metrics` list with the aggregation it belongs to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\Aggregation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

/**
 * The `metrics` value an aggregation declares, its name, and the properties
 * its schema declares.
 */
final class MetricsDeclaration {
	/**
	 * Constructor.
	 *
	 * @param string             $name     The aggregation name, for messages.
	 * @param mixed              $metrics  The declared `metrics` value.
	 * @param array<int, string> $propKeys Property names the schema declares.
	 */
	public function __construct(
		public readonly string $name,
		public readonly mixed $metrics,
		public readonly array $propKeys,
	) {
	}//end __construct()

	/**
	 * Where the entry at an index of this list sits.
	 *
	 * @param int $index The entry's index in `metrics`.
	 *
	 * @return MetricEntrySite
	 */
	public function siteAt(int $index): MetricEntrySite {
		return new MetricEntrySite(name: $this->name, index: $index, propKeys: $this->propKeys);
	}//end siteAt()
}//end class
