<?php

/**
 * AggregationAnnotationValidatorInterface: what callers outside the Search component use of AggregationAnnotationValidator.
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

/**
 * Validates the `x-openregister-aggregations` annotation of a schema.
 */
interface AggregationAnnotationValidatorInterface {

	/**
	 * Validate the `x-openregister-aggregations` annotation.
	 *
	 * @param array<string, mixed> $schema Full schema definition (must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}> Validation error list.
	 */
	public function validate(array $schema): array;
}//end interface
