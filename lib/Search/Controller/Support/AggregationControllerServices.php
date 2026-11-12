<?php

/**
 * OpenRegister aggregation controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Search\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller\Support;

use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of AggregationController, wired by DI autowiring.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class AggregationControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param AggregationRunnerInterface $runner    The aggregation runner.
	 * @param TimeseriesRequestValidator $validator Ad-hoc request validator.
	 * @param LoggerInterface|null       $logger    Where the unknown-filter-key warning goes (optional).
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly AggregationRunnerInterface $runner,
		public readonly TimeseriesRequestValidator $validator,
		public readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()
}//end class
