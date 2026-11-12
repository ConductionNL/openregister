<?php

/**
 * TimeseriesRequestValidatorInterface: what callers outside the Search component use of TimeseriesRequestValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;

/**
 * Validates the shape of an ad-hoc timeseries aggregation request.
 */
interface TimeseriesRequestValidatorInterface {

	/**
	 * Validate and normalise a request shape into an AggregationQuery.
	 *
	 * @param array<string, mixed> $input  The raw request shape.
	 * @param Schema               $schema The schema being aggregated (for field allow-listing).
	 *
	 * @return AggregationQuery The validated query value object.
	 *
	 * @throws InvalidArgumentException When any input rule is violated; the message is client-safe.
	 */
	public function validate(array $input, Schema $schema): AggregationQuery;
}//end interface
