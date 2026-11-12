<?php

/**
 * OpenRegister FacetRun
 *
 * One timed facet request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The facet query of one request and when the request started.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class FacetRun {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $query     The facet query.
	 * @param float                $startTime When the request started (microtime).
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		public readonly array $query,
		private readonly float $startTime,
	) {
	}//end __construct()

	/**
	 * The milliseconds since the request started, to two decimals.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function elapsedMs(): float {
		return round((microtime(true) - $this->startTime) * 1000, 2);
	}//end elapsedMs()
}//end class
