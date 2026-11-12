<?php

/**
 * StatisticsScope
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * Which magic tables an object statistics count covers: the registers, the
 * schemas and the register/schema pairs to leave out (ADR-011 Rule 2).
 *
 * @spec exclude ADR-011 parameter object of MagicStatisticsHandler::getStatistics(), not a capability of its own
 */
final class StatisticsScope {

	/**
	 * Constructor
	 *
	 * @param int|array|null $registerId The register id or ids to cover; null for every register.
	 * @param int|array|null $schemaId   The schema id or ids to cover; null for every schema.
	 * @param array          $exclude    Pairs never to cover, each with `register` and `schema`.
	 */
	public function __construct(
		public readonly int|array|null $registerId = null,
		public readonly int|array|null $schemaId = null,
		public readonly array $exclude = [],
	) {
	}//end __construct()
}//end class
